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
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front', 'login' )
			);
			return;
		}

		$editing = NULL;
		if ( \IPS\Request::i()->id )
		{
			try
			{
				$editing = \IPS\gddeals\Deal::load( \IPS\Request::i()->id );
			}
			catch ( \OutOfRangeException $e )
			{
				\IPS\Output::i()->error( 'gddeals_deal_not_found', '2GD102/1', 404 );
				return;
			}

			if ( !$editing->canEdit( $member ) )
			{
				\IPS\Output::i()->error( 'gddeals_no_edit_perm', '2GD102/2', 403 );
				return;
			}
		}

		if ( !$editing )
		{
			$canPost = FALSE;
			foreach ( \IPS\gddeals\Category::roots() as $cat )
			{
				if ( \IPS\gddeals\Deal::canCreate( $member, $cat ) )
				{
					$canPost = TRUE;
					break;
				}
			}

			if ( !$canPost )
			{
				\IPS\Output::i()->error( 'gddeals_submit_no_perm', '2GD100/1', 403 );
				return;
			}
		}

		$buttonKey = $editing ? 'gddeals_edit_deal' : 'gddeals_submit_button';
		$form = new \IPS\Helpers\Form( 'gddeals_submit', $buttonKey );

		$defCat = $editing ? $editing->container() : NULL;
		$form->add( new \IPS\Helpers\Form\Node( 'gddeals_f_category', $defCat, TRUE, [
			'class'           => 'IPS\\gddeals\\Category',
			'permissionCheck' => 'add',
			'subnodes'        => FALSE,
		] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_title', $editing ? $editing->title : NULL, TRUE, [
			'maxLength' => 255,
		] ) );

		$postTypes = [
			'product'     => $member->language()->addToStack( 'gddeals_posttype_product' ),
			'bundle'      => $member->language()->addToStack( 'gddeals_posttype_bundle' ),
			'storewide'   => $member->language()->addToStack( 'gddeals_posttype_storewide' ),
			'clearance'   => $member->language()->addToStack( 'gddeals_posttype_clearance' ),
			'coupon'      => $member->language()->addToStack( 'gddeals_posttype_coupon' ),
			'price_error' => $member->language()->addToStack( 'gddeals_posttype_price_error' ),
		];
		$form->add( new \IPS\Helpers\Form\Select( 'gddeals_f_posttype', $editing ? $editing->post_type : 'product', TRUE, [
			'options' => $postTypes,
		] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_retailer_name', $editing ? $editing->retailer_name : NULL, TRUE, [
			'maxLength' => 150,
		] ) );

		$retailerTypes = [
			'online'  => $member->language()->addToStack( 'gddeals_retailertype_online' ),
			'instore' => $member->language()->addToStack( 'gddeals_retailertype_instore' ),
			'both'    => $member->language()->addToStack( 'gddeals_retailertype_both' ),
		];
		$form->add( new \IPS\Helpers\Form\Select( 'gddeals_f_retailer_type', $editing ? $editing->retailer_type : 'online', FALSE, [
			'options' => $retailerTypes,
		] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_store_location', $editing ? $editing->store_location : NULL, FALSE, [
			'maxLength' => 200,
		] ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_f_deal_price', $editing ? $editing->deal_price : NULL, FALSE, [
			'decimals' => 2,
			'min'      => 0,
		] ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_f_original_price', $editing ? $editing->original_price : NULL, FALSE, [
			'decimals' => 2,
			'min'      => 0,
		] ) );

		$defUrl = ( $editing && $editing->deal_url ) ? new \IPS\Http\Url( $editing->deal_url ) : NULL;
		$form->add( new \IPS\Helpers\Form\Url( 'gddeals_f_deal_url', $defUrl, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gddeals_f_promo_code', $editing ? $editing->promo_code : NULL, FALSE, [
			'maxLength' => 100,
		] ) );

		$defImage = ( $editing && $editing->image_url ) ? new \IPS\Http\Url( $editing->image_url ) : NULL;
		$form->add( new \IPS\Helpers\Form\Url( 'gddeals_f_image', $defImage, FALSE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_f_free_shipping', $editing ? (bool) $editing->free_shipping : FALSE, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gddeals_f_shipping_cost', $editing ? $editing->shipping_cost : NULL, FALSE, [
			'decimals' => 2,
			'min'      => 0,
		] ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gddeals_f_description', $editing ? $editing->description : NULL, FALSE ) );

		$defExpires = ( $editing && $editing->expires_at ) ? \IPS\DateTime::ts( $editing->expires_at ) : NULL;
		$form->add( new \IPS\Helpers\Form\Date( 'gddeals_f_expires', $defExpires, FALSE ) );

		if ( $values = $form->values() )
		{
			if ( $editing )
			{
				$editing->category_id = $values['gddeals_f_category']->_id;
				$this->_applyDealValues( $editing, $values, $member );
				$editing->save();
				\IPS\Output::i()->redirect( $editing->url() );
				return;
			}
			else
			{
				$category = $values['gddeals_f_category'];

				if ( !\IPS\gddeals\Deal::canCreate( $member, $category ) )
				{
					\IPS\Output::i()->error( 'gddeals_submit_no_perm', '2GD100/2', 403 );
					return;
				}

				$deal = \IPS\gddeals\Deal::createItem( $member, \IPS\Request::i()->ipAddress(), \IPS\DateTime::create(), $category, NULL );
				$this->_applyDealValues( $deal, $values, $member );
				$deal->save();

				if ( $deal->hidden() === 1 )
				{
					try
					{
						\IPS\core\Approval::loadFromContent( get_class( $deal ), $deal->id );
					}
					catch ( \OutOfRangeException $e )
					{
						$reason = 'node';
						if ( $deal->author()->mod_posts )
						{
							$reason = 'user';
						}
						elseif ( $deal->author()->group['g_mod_preview'] )
						{
							$reason = 'group';
						}
						\IPS\core\Approval::create( $deal, $reason );
					}
				}

				\IPS\Output::i()->redirect( $deal->url() );
				return;
			}
		}

		$titleKey = $editing ? 'gddeals_edit_deal_title' : 'gddeals_submit_title';
		\IPS\Output::i()->title  = $member->language()->addToStack( $titleKey );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'deals', 'gddeals', 'front' )->submit( $form );
	}

	protected function _applyDealValues( \IPS\gddeals\Deal $deal, array $values, \IPS\Member $member ): void
	{
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
		$deal->source_badge   = $member->isAdmin() ? 'admin' : 'community';

		if ( $deal->original_price > 0 && $deal->deal_price > 0 && $deal->original_price > $deal->deal_price )
		{
			$deal->discount_pct = round( ( 1 - ( $deal->deal_price / $deal->original_price ) ) * 100, 2 );
		}
		else
		{
			$deal->discount_pct = 0;
		}
	}
}
class submit extends _submit {}
