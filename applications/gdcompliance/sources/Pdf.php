<?php
/**
 * @brief  GD Compliance — pure-PHP PDF text extractor (v1.6.2)
 *
 * The plugin lives on a server WITHOUT: pdftotext, gs, mutool, composer,
 * or Smalot/PdfParser. The original regex-Tj fallback in Roster::extractPdfText
 * only worked on uncompressed content streams — but MD's PS5-405 PDF and
 * every other modern government PDF ships with flate-compressed streams,
 * so extraction silently returned empty and the fetchers reported
 * "extractor: none".
 *
 * This class re-implements the subset of PDF text extraction needed to
 * pull rows out of standard text-mode PDFs from state government sources
 * (MD MSP, MA AG, RI, etc.) using ONLY:
 *   - zlib_decode() / gzuncompress() from the zlib extension (bundled
 *     with every PHP build)
 *   - PCRE
 *
 * What it does:
 *   1. Walk the object table looking for `<< ... >> stream ... endstream`
 *      blocks
 *   2. For each stream: if `/Filter /FlateDecode` (or `/Fl`) is in the
 *      preceding dictionary, decompress with zlib_decode
 *   3. Scan the decompressed content stream for text-show operators:
 *         (literal string) Tj
 *         [(array of strings) TJ
 *         <hex string> Tj / TJ
 *         "\rho c d text"  (Tj with word/char spacing)
 *      Handles PDF escapes: \n \r \t \\ \( \) \ddd (octal), and
 *      BT/ET blocks to group tokens on the same line where reasonable
 *   4. Fallback: if flate fails on a stream, run the old regex-Tj over
 *      the raw bytes (catches rare uncompressed streams)
 *
 * Does NOT handle: custom CMaps / ToUnicode remaps (rare in text-mode
 * PDFs), inline images, RC4 encryption. Returns whatever it can and
 * lets the caller decide if the row count looks reasonable.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Pdf
{
	/**
	 * Extract text from PDF bytes. Returns the concatenated text of every
	 * content stream we could decode, one BT/ET block per line-ish.
	 * Never throws — returns '' on total failure.
	 */
	public static function extractText( string $bytes ): string
	{
		if ( $bytes === '' || strncmp( $bytes, '%PDF', 4 ) !== 0 ) { return ''; }

		$out = [];
		$offset = 0;
		$len    = strlen( $bytes );

		/* Iterate `stream` markers so we can pair each with its preceding
		   dictionary. We look ahead a bounded window to keep this O(N). */
		while ( ( $streamPos = strpos( $bytes, "stream", $offset ) ) !== false )
		{
			$endPos = strpos( $bytes, "endstream", $streamPos );
			if ( $endPos === false ) { break; }

			/* The `stream` keyword is followed by a single \r\n or \n
			   before the raw bytes begin. Skip that so we don't include
			   a stray byte in decompression. */
			$startData = $streamPos + 6;
			if ( isset( $bytes[ $startData ] ) )
			{
				$c = $bytes[ $startData ];
				if ( $c === "\r" ) { $startData++; if ( isset( $bytes[ $startData ] ) && $bytes[ $startData ] === "\n" ) { $startData++; } }
				elseif ( $c === "\n" ) { $startData++; }
			}
			$rawData = substr( $bytes, $startData, $endPos - $startData );

			/* Look back for the preceding `<< ... >>` dictionary that
			   describes this stream. Bounded window of 2000 bytes. */
			$lookback = max( 0, $streamPos - 2000 );
			$window   = substr( $bytes, $lookback, $streamPos - $lookback );
			$dict     = '';
			if ( preg_match( '/<<(.*?)>>\s*$/s', $window, $m ) )
			{
				$dict = $m[1];
			}

			$decoded = self::maybeDecompress( $rawData, $dict );
			if ( $decoded !== '' )
			{
				$text = self::extractTextFromContent( $decoded );
				if ( $text !== '' ) { $out[] = $text; }
			}

			$offset = $endPos + 9;
		}

		$joined = trim( implode( "\n", $out ) );

		/* If we got nothing from flate/content streams, try the ancient
		   regex-Tj fallback across the raw bytes as a last resort — catches
		   PDFs with fully uncompressed content that our loop above may have
		   skipped when dict parsing failed. */
		if ( $joined === '' )
		{
			$joined = self::regexTjFallback( $bytes );
		}

		return $joined;
	}

	/**
	 * If the dictionary declares FlateDecode, try zlib_decode; else return
	 * the raw stream data. Returns '' on failure so the caller skips.
	 */
	protected static function maybeDecompress( string $data, string $dict ): string
	{
		$hasFlate = ( strpos( $dict, 'FlateDecode' ) !== false || preg_match( '/\/Fl\b/', $dict ) );
		if ( $hasFlate )
		{
			/* zlib_decode handles both zlib and gzip prefixed data; on
			   raw deflate we retry with gzinflate. */
			$decoded = @zlib_decode( $data );
			if ( $decoded === false || $decoded === null )
			{
				$decoded = @gzuncompress( $data );
			}
			if ( $decoded === false || $decoded === null )
			{
				$decoded = @gzinflate( $data );
			}
			return ( $decoded === false || $decoded === null ) ? '' : (string) $decoded;
		}
		/* Uncompressed content stream. */
		return $data;
	}

	/**
	 * Extract text from a decoded content stream. Walks BT ... ET blocks
	 * and pulls string arguments to Tj / TJ / ' / " operators. Adds
	 * a newline between BT/ET blocks to keep row-per-line structure
	 * for tabular PDFs.
	 */
	protected static function extractTextFromContent( string $content ): string
	{
		$out = [];

		/* Grab every BT ... ET block. If there are no BT/ET markers,
		   treat the whole stream as one block. */
		if ( preg_match_all( '/BT\s*(.*?)\s*ET/s', $content, $blocks ) )
		{
			$parts = $blocks[1];
		}
		else
		{
			$parts = [ $content ];
		}

		foreach ( $parts as $part )
		{
			$line = self::extractStringsFromBlock( $part );
			if ( $line !== '' )
			{
				$out[] = $line;
			}
		}

		return trim( implode( "\n", $out ) );
	}

	/**
	 * Extract text-show string arguments from a single content block.
	 * Handles (literal) Tj, [(a)(b)] TJ, and their double-quote / apostrophe
	 * variants. Concatenates on one line with spaces where the operator
	 * implies a token break.
	 */
	protected static function extractStringsFromBlock( string $block ): string
	{
		/* Emit a NEWLINE sentinel whenever a Td/TD/T* text-line-advance
		   operator appears; otherwise text-show strings are joined with
		   spaces. This preserves row-per-line structure for tabular PDFs
		   (MD roster, MA roster, etc.) which is what the downstream
		   parsers expect. */
		$out = [];
		$len = strlen( $block );
		$i   = 0;

		while ( $i < $len )
		{
			$c = $block[ $i ];

			if ( $c === '(' )
			{
				$str = self::readLiteralString( $block, $i );
				if ( $str !== null ) { $out[] = self::decodePdfString( $str ); }
				continue;
			}

			if ( $c === '<' )
			{
				$closeI = strpos( $block, '>', $i + 1 );
				if ( $closeI !== false )
				{
					$hex = substr( $block, $i + 1, $closeI - $i - 1 );
					$hex = preg_replace( '/\s+/', '', (string) $hex );
					if ( $hex !== null && ctype_xdigit( $hex ) )
					{
						if ( strlen( $hex ) % 2 === 1 ) { $hex .= '0'; }
						$decoded = @hex2bin( $hex );
						if ( $decoded !== false ) { $out[] = self::decodePdfString( $decoded ); }
					}
					$i = $closeI + 1;
					continue;
				}
			}

			/* Look for line-advance operators. Match at word boundaries
			   so we don't fire on "Td" inside a stream identifier. */
			if ( ( $c === 'T' || $c === 't' ) && $i + 1 < $len )
			{
				$op = substr( $block, $i, 2 );
				$prev = $i > 0 ? $block[ $i - 1 ] : ' ';
				$next = $i + 2 < $len ? $block[ $i + 2 ] : ' ';
				$isBoundary = !ctype_alnum( $prev ) && ( $op === 'T*' || !ctype_alnum( $next ) );
				if ( $isBoundary && ( $op === 'Td' || $op === 'TD' || $op === 'T*' ) )
				{
					$out[] = "\n";
					$i += 2;
					continue;
				}
			}

			$i++;
		}

		/* Join tokens. Newline sentinels remain literal so the caller
		   sees row structure. Collapse runs of spaces. */
		$joined = implode( ' ', $out );
		$joined = preg_replace( '/[ \t]+/', ' ', $joined ) ?? $joined;
		$joined = preg_replace( '/ *\n */', "\n", $joined ) ?? $joined;
		$joined = preg_replace( '/\n{2,}/', "\n", $joined ) ?? $joined;
		return trim( $joined );
	}

	/**
	 * Read a PDF literal string starting at $block[$i] === '('. Advances
	 * $i by reference to the byte AFTER the matching ')'. Returns the raw
	 * (still-escaped) string content, or null on unterminated.
	 */
	protected static function readLiteralString( string $block, int &$i ): ?string
	{
		$len   = strlen( $block );
		$depth = 0;
		$out   = '';
		if ( $block[ $i ] !== '(' ) { return null; }
		$depth = 1;
		$i++;

		while ( $i < $len )
		{
			$c = $block[ $i ];

			if ( $c === '\\' && $i + 1 < $len )
			{
				$out .= $block[ $i ] . $block[ $i + 1 ];
				$i += 2;
				continue;
			}

			if ( $c === '(' )
			{
				$depth++;
				$out .= $c;
				$i++;
				continue;
			}

			if ( $c === ')' )
			{
				$depth--;
				if ( $depth === 0 )
				{
					$i++;
					return $out;
				}
				$out .= $c;
				$i++;
				continue;
			}

			$out .= $c;
			$i++;
		}

		return null;
	}

	/**
	 * Decode PDF string escape sequences: \n \r \t \b \f \\ \( \) \ddd
	 * (octal). Leaves everything else as-is.
	 */
	protected static function decodePdfString( string $s ): string
	{
		$out = '';
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ )
		{
			$c = $s[ $i ];
			if ( $c !== '\\' ) { $out .= $c; continue; }
			if ( ! isset( $s[ $i + 1 ] ) ) { continue; }
			$n = $s[ ++$i ];
			switch ( $n )
			{
				case 'n':  $out .= "\n"; break;
				case 'r':  $out .= "\r"; break;
				case 't':  $out .= "\t"; break;
				case 'b':  $out .= "\x08"; break;
				case 'f':  $out .= "\x0C"; break;
				case '\\': $out .= '\\';  break;
				case '(':  $out .= '(';   break;
				case ')':  $out .= ')';   break;
				case "\n": break;  /* line continuation */
				case "\r":
					if ( isset( $s[ $i + 1 ] ) && $s[ $i + 1 ] === "\n" ) { $i++; }
					break;
				default:
					if ( ctype_digit( $n ) )
					{
						$oct = $n;
						if ( isset( $s[ $i + 1 ] ) && ctype_digit( $s[ $i + 1 ] ) ) { $oct .= $s[ ++$i ]; }
						if ( isset( $s[ $i + 1 ] ) && ctype_digit( $s[ $i + 1 ] ) ) { $oct .= $s[ ++$i ]; }
						$out .= chr( octdec( $oct ) & 0xFF );
					}
					else
					{
						$out .= $n;
					}
			}
		}
		return $out;
	}

	/**
	 * Last-resort Tj/TJ regex over raw bytes — the pre-v1.6.2 fallback.
	 * Useful only on fully uncompressed content streams; kept so we don't
	 * regress environments where the flate walk misses a stream.
	 */
	protected static function regexTjFallback( string $bytes ): string
	{
		$out = '';
		if ( preg_match_all( '/\(((?:\\\\.|[^()\\\\])*)\)\s*(?:Tj|TJ)/', $bytes, $m ) )
		{
			foreach ( $m[1] as $s )
			{
				$out .= self::decodePdfString( $s ) . "\n";
			}
		}
		return trim( $out );
	}

	/**
	 * Sanity check — does this blob start with %PDF- and end with %%EOF?
	 * Cheap way to reject an HTML 403/404 page returned by a blocking CDN.
	 */
	public static function looksLikePdf( string $bytes ): bool
	{
		if ( strlen( $bytes ) < 100 ) { return false; }
		if ( strncmp( $bytes, '%PDF-', 5 ) !== 0 ) { return false; }
		return true;
	}
}

class Pdf extends _Pdf {}
