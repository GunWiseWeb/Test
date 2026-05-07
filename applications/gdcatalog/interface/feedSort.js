;( function( $, _, undefined )
{
	"use strict";

	/**
	 * gdcatalog feed list drag-and-drop reorder.
	 * Loaded by modules/admin/catalog/feeds.php on the manage() action.
	 *
	 * Template provides:
	 *   <table data-reorder-url="https://.../do=reorder&csrfKey=...">
	 *     <tbody class="gdcatalog-sortable">
	 *       <tr data-feed-id="1">...</tr>
	 *       ...
	 */

	function init()
	{
		var table = document.querySelector( 'table[data-reorder-url]' );
		if ( !table || typeof jQuery === 'undefined' || !jQuery.fn.sortable )
		{
			return;
		}

		var url = table.getAttribute( 'data-reorder-url' );

		jQuery( table ).find( 'tbody.gdcatalog-sortable' ).sortable( {
			handle: '.gdcatalog-drag-handle',
			placeholder: 'ipsTable--ghost',
			helper: function( e, tr )
			{
				var originals = tr.children();
				var helperRow = tr.clone();
				helperRow.children().each( function( index )
				{
					jQuery( this ).width( originals.eq( index ).width() );
				} );
				return helperRow;
			},
			stop: function()
			{
				var ids = jQuery( table ).find( 'tbody tr[data-feed-id]' ).map( function()
				{
					return this.getAttribute( 'data-feed-id' );
				} ).get();

				jQuery.ajax( {
					url: url,
					method: 'POST',
					data: {
						ids: ids,
						csrfKey: ( typeof ips !== 'undefined' && ips.utils && ips.utils.csrfKey ) ? ips.utils.csrfKey : ''
					},
					success: function()
					{
						window.location.reload();
					}
				} );
			}
		} );

		/* Confirm dialog for delete buttons */
		document.querySelectorAll( 'a[data-confirm-message]' ).forEach( function( a )
		{
			a.addEventListener( 'click', function( e )
			{
				var msg = a.getAttribute( 'data-confirm-message' );
				if ( !confirm( msg ) )
				{
					e.preventDefault();
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' )
	{
		document.addEventListener( 'DOMContentLoaded', init );
	}
	else
	{
		init();
	}
} )( jQuery );
