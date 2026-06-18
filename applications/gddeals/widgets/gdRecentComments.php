<?php
namespace IPS\gddeals\widgets;

use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Output;
use IPS\Theme;
use IPS\Widget\Customizable;
use IPS\Widget\PermissionCache;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class gdRecentComments extends PermissionCache implements Customizable
{
	public string $key = 'gdRecentComments';
	public string $app = 'gddeals';

	public function init(): void
	{
		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'deals.css', 'gddeals', 'front' ) );
		$this->template( array( Theme::i()->getTemplate( 'widgets', 'gddeals', 'front' ), $this->key ) );
		parent::init();
	}

	public function configuration( Form &$form=null ): Form
	{
		$form = parent::configuration( $form );
		$form->add( new Number( 'toshow', $this->configuration['toshow'] ?? 5, TRUE ) );
		return $form;
	}

	public function render(): string
	{
		$limit = (int) ( $this->configuration['toshow'] ?? 5 );
		if ( $limit < 1 ) { $limit = 5; }

		$rows = array();
		try
		{
			foreach ( \IPS\Db::i()->select( 'id', 'gd_deal_comments', array( 'approved=1' ), 'comment_date DESC', array( 0, $limit ) ) as $cid )
			{
				try
				{
					$c    = \IPS\gddeals\Deal\Comment::load( $cid );
					$deal = $c->item();
					$snippet = trim( strip_tags( (string) $c->mapped('content') ) );
					if ( mb_strlen( $snippet ) > 120 ) { $snippet = mb_substr( $snippet, 0, 120 ) . "\u{2026}"; }
					$rows[] = array(
						'author'     => $c->author()->name,
						'deal_title' => $deal->title,
						'url'        => (string) $c->url(),
						'snippet'    => $snippet,
						'date'       => (string) \IPS\DateTime::ts( $c->mapped('date') )->relative(),
					);
				}
				catch ( \Throwable $e ) {}
			}
		}
		catch ( \Throwable $e ) {}

		if ( empty( $rows ) ) { return ''; }
		return $this->output( $rows );
	}
}
