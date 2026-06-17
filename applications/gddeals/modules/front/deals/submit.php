<?php
namespace IPS\gddeals\modules\front\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _submit extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage()
	{
		$member = \IPS\Member::loggedIn();

		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' ) );
			return;
		}

		$editing = NULL;
		if ( $editId = (int) ( \IPS\Request::i()->id ?? 0 ) )
		{
			try { $editing = \IPS\gddeals\Deal::load( $editId ); }
			catch ( \OutOfRangeException $e ) { \IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD100/3', 404 ); return; }
			if ( !$editing->canEdit( $member ) ) { \IPS\Output::i()->error( 'gddeals_submit_no_perm', '2GD100/4', 403 ); return; }
		}
		else
		{
			$canPost = FALSE;
			foreach ( \IPS\gddeals\Category::roots() as $cat )
			{
				if ( \IPS\gddeals\Deal::canCreate( $member, $cat ) ) { $canPost = TRUE; break; }
			}
			if ( !$canPost ) { \IPS\Output::i()->error( 'gddeals_submit_no_perm', '2GD100/1', 403 ); return; }
		}

		$form = new \IPS\Helpers\Form( 'gddeals_submit', $editing ? 'save' : 'gddeals_submit_button' );

		$form->add( new \IPS\Helpers\Form\Node( 'gddeals_f_category', $editing ? $editing->category_id : NULL, TRUE, [
			'class' => 'IPS\\gddeals\\Category', 'permissionCheck' => 'add', 'subnodes' => FALSE,
		] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_title', $editing ? $editing->title : NULL, TRUE, [ 'maxLength' => 255 ] ) );

		$postTypes = [
			'product'     => $member->language()->addToStack( 'gddeals_posttype_product' ),
			'bundle'      => $member->language()->addToStack( 'gddeals_posttype_bundle' ),
			'storewide'   => $member->language()->addToStack( 'gddeals_posttype_storewide' ),
			'clearance'   => $member->language()->addToStack( 'gddeals_posttype_clearance' ),
			'coupon'      => $member->language()->addToStack( 'gddeals_posttype_coupon' ),
			'price_error' => $member->language()->addToStack( 'gddeals_posttype_price_error' ),
		];
		$form->add( new \IPS\Helpers\Form\Select( 'gddeals_f_posttype', $editing ? $editing->post_type : 'product', TRUE, [ 'options' => $postTypes ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_retailer_name', $editing ? $editing->retailer_name : NULL, TRUE, [ 'maxLength' => 150 ] ) );

		$retailerTypes = [
			'online'  => $member->language()->addToStack( 'gddeals_retailertype_online' ),
			'instore' => $member->language()->addToStack( 'gddeals_retailertype_instore' ),
			'both'    => $member->language()->addToStack( 'gddeals_retailertype_both' ),
		];
		$form->add( new \IPS\Helpers\Form\Select( 'gddeals_f_retailer_type', $editing ? $editing->retailer_type : 'online', FALSE, [ 'options' => $retailerTypes ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_store_location', $editing ? $editing->store_location : NULL, FALSE, [ 'maxLength' => 200 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_f_deal_price', ( $editing and $editing->deal_price !== NULL ) ? $editing->deal_price : NULL, FALSE, [ 'decimals' => 2, 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_f_original_price', ( $editing and $editing->original_price !== NULL ) ? $editing->original_price : NULL, FALSE, [ 'decimals' => 2, 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gddeals_f_deal_url', $editing ? $editing->deal_url : NULL, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_promo_code', $editing ? $editing->promo_code : NULL, FALSE, [ 'maxLength' => 100 ] ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gddeals_f_image', $editing ? $editing->image_url : NULL, FALSE ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_f_free_shipping', $editing ? (bool) $editing->free_shipping : FALSE, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_f_shipping_cost', ( $editing and $editing->shipping_cost !== NULL ) ? $editing->shipping_cost : NULL, FALSE, [ 'decimals' => 2, 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gddeals_f_description', $editing ? $editing->description : NULL, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Date( 'gddeals_f_expires', ( $editing and $editing->expires_at ) ? \IPS\DateTime::ts( $editing->expires_at ) : NULL, FALSE ) );

		if ( $values = $form->values() )
		{
			$category = $values['gddeals_f_category'];

			if ( $editing )
			{
				$deal = $editing;
				$deal->category_id = $category->_id;
			}
			else
			{
				if ( !\IPS\gddeals\Deal::canCreate( $member, $category ) ) { \IPS\Output::i()->error( 'gddeals_submit_no_perm', '2GD100/2', 403 ); return; }
				$deal = \IPS\gddeals\Deal::createItem( $member, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create(), $category, NULL );
			}

			$deal->title          = $values['gddeals_f_title'];
			$deal->description    = $values['gddeals_f_description'];
			$deal->post_type      = $values['gddeals_f_posttype'];
			$deal->retailer_name  = $values['gddeals_f_retailer_name'];
			$deal->retailer_type  = $values['gddeals_f_retailer_type'];
			$deal->store_location = $values['gddeals_f_store_location'];
			$deal->deal_price     = $values['gddeals_f_deal_price'] ?: NULL;
			$deal->original_price = $values['gddeals_f_original_price'] ?: NULL;
			$deal->deal_url       = (string) $values['gddeals_f_deal_url'];
			$deal->promo_code     = $values['gddeals_f_promo_code'];
			$deal->free_shipping  = $values['gddeals_f_free_shipping'] ? 1 : 0;
			$deal->shipping_cost  = $values['gddeals_f_shipping_cost'] ?: NULL;
			$deal->expires_at     = $values['gddeals_f_expires'] ? $values['gddeals_f_expires']->getTimestamp() : NULL;
			$deal->image_url      = $values['gddeals_f_image'] ? (string) $values['gddeals_f_image'] : NULL;
			if ( !$editing )
			{
				$deal->source_badge = $member->isAdmin() ? 'admin' : 'community';
			}

			if ( $deal->original_price > 0 && $deal->deal_price > 0 && $deal->original_price > $deal->deal_price )
			{
				$deal->discount_pct = round( ( 1 - ( $deal->deal_price / $deal->original_price ) ) * 100, 2 );
			}
			else
			{
				$deal->discount_pct = 0;
			}

			$deal->save();

			if ( !$editing && $deal->hidden() === 1 )
			{
				try
				{
					\IPS\core\Approval::loadFromContent( get_class( $deal ), $deal->id );
				}
				catch ( \OutOfRangeException $e )
				{
					$reason = 'node';
					if ( $deal->author()->mod_posts ) { $reason = 'user'; }
					elseif ( $deal->author()->group['g_mod_preview'] ) { $reason = 'group'; }
					\IPS\core\Approval::create( $deal, $reason );
				}
			}

			\IPS\Output::i()->redirect( $deal->url() );
			return;
		}

		\IPS\Output::i()->title  = $member->language()->addToStack( $editing ? 'gddeals_edit_title' : 'gddeals_submit_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->submit( $form );
	}
}
class submit extends _submit {}
