/**
 * Appends the "Persons" column to Analytics > Orders.
 *
 * ReportTable applies `woocommerce_admin_report_table` to
 * { endpoint, headers, rows, totals, summary, items } right before rendering,
 * and the client-side CSV download reuses the same headers/rows — so filtering
 * here covers both the table and the download button.
 *
 * `items.data` is the raw REST payload in row order; `person_count` is added to
 * it by register_rest_field() in includes/person-count.php.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks ) {
		return;
	}

	var settings = window.alttagPersonColumn || {};
	var label = settings.label || 'Persons';

	wp.hooks.addFilter(
		'woocommerce_admin_report_table',
		'alttag-registrations-customization/person-count',
		function ( tableData ) {
			if ( ! tableData || tableData.endpoint !== 'orders' ) {
				return tableData;
			}

			var headers = tableData.headers || [];
			var rows = tableData.rows || [];
			var items = ( tableData.items && tableData.items.data ) || [];

			if ( ! rows.length || ! items.length ) {
				return tableData;
			}

			// The filter can run more than once per render; do not stack columns.
			var alreadyAdded = headers.some( function ( header ) {
				return header && header.key === 'person_count';
			} );
			if ( alreadyAdded ) {
				return tableData;
			}

			return Object.assign( {}, tableData, {
				headers: headers.concat( [ {
					label: label,
					key: 'person_count',
					required: false,
					isSortable: false,
					isNumeric: true,
				} ] ),
				rows: rows.map( function ( row, index ) {
					var item = items[ index ] || {};
					var count = typeof item.person_count === 'number' ? item.person_count : 0;

					return row.concat( [ {
						display: String( count ),
						value: count,
					} ] );
				} ),
			} );
		}
	);
} )( window.wp );
