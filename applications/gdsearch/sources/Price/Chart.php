<?php
namespace IPS\gdsearch\Price;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Server-side SVG price-history line chart (no JS — safe inside IPS templates).
 */
class _Chart
{
	/**
	 * @param array<int, array{date:string, price:float}> $series  ascending, forward-filled
	 */
	public static function svg( array $series ): string
	{
		$n = count( $series );
		if ( $n < 2 ) { return ''; }

		$w = 640; $h = 200; $padL = 56; $padR = 16; $padT = 16; $padB = 30;
		$plotW = $w - $padL - $padR;
		$plotH = $h - $padT - $padB;

		$prices = array_column( $series, 'price' );
		$min = min( $prices );
		$max = max( $prices );
		if ( $max <= $min ) { $max = $min + 1; }

		$xAt = static fn( int $i ): float => $padL + ( $n <= 1 ? 0 : ( $i / ( $n - 1 ) ) * $plotW );
		$yAt = static fn( float $p ): float => $padT + ( 1 - ( ( $p - $min ) / ( $max - $min ) ) ) * $plotH;

		$pts = [];
		foreach ( $series as $i => $pt ) { $pts[] = round( $xAt( $i ), 1 ) . ',' . round( $yAt( (float) $pt['price'] ), 1 ); }

		$base   = round( $padT + $plotH, 1 );
		$firstX = round( $xAt( 0 ), 1 );
		$lastX  = round( $xAt( $n - 1 ), 1 );
		$area   = 'M ' . $firstX . ',' . $base . ' L ' . implode( ' L ', $pts ) . ' L ' . $lastX . ',' . $base . ' Z';
		$line   = 'M ' . implode( ' L ', $pts );

		$cur  = $series[ $n - 1 ];
		$curX = round( $xAt( $n - 1 ), 1 );
		$curY = round( $yAt( (float) $cur['price'] ), 1 );

		$money = static fn( float $v ): string => '$' . number_format( $v, 2 );
		$esc   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );

		$midY = round( $padT + $plotH / 2, 1 );
		$maxLabel = $esc( $money( $max ) );
		$minLabel = $esc( $money( $min ) );
		$midLabel = $esc( $money( ( $min + $max ) / 2 ) );
		$startLbl = $esc( $series[0]['date'] );
		$endLbl   = $esc( $cur['date'] );
		$curLbl   = $esc( $money( (float) $cur['price'] ) );

		$blue = '#1E40AF';
		$grid = '#E5E7EB';
		$txt  = '#6B7280';

		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" aria-label="Price history" style="max-width:640px;font-family:Inter,system-ui,sans-serif">';
		$svg .= '<line x1="' . $padL . '" y1="' . round( $padT, 1 ) . '" x2="' . ( $w - $padR ) . '" y2="' . round( $padT, 1 ) . '" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<line x1="' . $padL . '" y1="' . $midY . '" x2="' . ( $w - $padR ) . '" y2="' . $midY . '" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<line x1="' . $padL . '" y1="' . $base . '" x2="' . ( $w - $padR ) . '" y2="' . $base . '" stroke="' . $grid . '" stroke-width="1"/>';
		$svg .= '<text x="' . ( $padL - 8 ) . '" y="' . round( $padT + 4, 1 ) . '" text-anchor="end" font-size="11" fill="' . $txt . '">' . $maxLabel . '</text>';
		$svg .= '<text x="' . ( $padL - 8 ) . '" y="' . round( $midY + 4, 1 ) . '" text-anchor="end" font-size="11" fill="' . $txt . '">' . $midLabel . '</text>';
		$svg .= '<text x="' . ( $padL - 8 ) . '" y="' . round( $base + 4, 1 ) . '" text-anchor="end" font-size="11" fill="' . $txt . '">' . $minLabel . '</text>';
		$svg .= '<path d="' . $area . '" fill="' . $blue . '" fill-opacity="0.08"/>';
		$svg .= '<path d="' . $line . '" fill="none" stroke="' . $blue . '" stroke-width="2" stroke-linejoin="round"/>';
		$svg .= '<circle cx="' . $curX . '" cy="' . $curY . '" r="3.5" fill="' . $blue . '"/>';
		$svg .= '<text x="' . $curX . '" y="' . round( $curY - 8, 1 ) . '" text-anchor="end" font-size="11" font-weight="700" fill="' . $blue . '">' . $curLbl . '</text>';
		$svg .= '<text x="' . $padL . '" y="' . ( $h - 8 ) . '" text-anchor="start" font-size="11" fill="' . $txt . '">' . $startLbl . '</text>';
		$svg .= '<text x="' . ( $w - $padR ) . '" y="' . ( $h - 8 ) . '" text-anchor="end" font-size="11" fill="' . $txt . '">' . $endLbl . '</text>';
		$svg .= '</svg>';

		return $svg;
	}
}

class Chart extends _Chart {}
