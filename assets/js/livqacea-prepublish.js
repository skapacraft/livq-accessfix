/**
 * LivQ AccessFix - Gutenberg Pre-Publish Accessibility Panel
 *
 * Registers a PluginPrePublishPanel that runs two real-time accessibility
 * checks on the current post content before the editor clicks "Publish":
 *
 *   Check A - Images without alt text (WCAG 1.1.1)
 *     Scans all core/image blocks. If the `alt` attribute is empty or absent,
 *     the image will be inaccessible to screen reader users.
 *
 *   Check B - Links with naked URLs as visible text (WCAG 2.4.4)
 *     Scans the serialised `content` attribute of paragraph and other rich-text
 *     blocks. An anchor whose visible text is a raw URL (e.g. https://…) gives
 *     no meaningful context to AT users or search engines.
 *
 * Architecture notes
 * ------------------
 * • No JSX / no build step: uses wp.element.createElement() directly.
 *   Compatible with any WordPress installation without a local Node toolchain.
 * • All wp.* globals are guaranteed available when this file loads because
 *   wp-plugins, wp-edit-post, wp-element, wp-data, wp-i18n are listed as
 *   dependencies in LIVQACEA_Advanced::enqueue_prepublish_script().
 * • useSelect() re-runs automatically whenever block state changes - the panel
 *   updates in real time as the editor types, with zero polling overhead.
 * • String literals are wrapped in __() so they are picked up by wp i18n make-pot.
 *
 * @package LivQ_AccessFix
 * @since   1.1.0
 */

( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Destructure WP globals - fail gracefully if the editor is not loaded */
	/* ------------------------------------------------------------------ */

	if (
		! window.wp ||
		! wp.plugins ||
		! wp.editPost ||
		! wp.element ||
		! wp.data ||
		! wp.i18n
	) {
		return;
	}

	var registerPlugin        = wp.plugins.registerPlugin;
	var PluginPrePublishPanel = wp.editPost.PluginPrePublishPanel;
	var createElement         = wp.element.createElement;
	var useSelect             = wp.data.useSelect;
	var __                    = wp.i18n.__;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Recursively flattens a block tree (including innerBlocks) into a flat array.
	 *
	 * Gutenberg stores nested blocks (columns, groups, etc.) in innerBlocks;
	 * we must recurse to catch images or paragraphs at any nesting depth.
	 *
	 * @param {Array} blocks Top-level blocks array from getBlocks().
	 * @returns {Array} Flat array of all block objects.
	 */
	function flattenBlocks( blocks ) {
		var result = [];
		blocks.forEach( function ( block ) {
			result.push( block );
			if ( block.innerBlocks && block.innerBlocks.length ) {
				result = result.concat( flattenBlocks( block.innerBlocks ) );
			}
		} );
		return result;
	}

	/**
	 * Regex that matches <a> tags whose entire visible text is a raw URL.
	 *
	 * Pattern breakdown:
	 *   <a\b[^>]*>   - opening tag with any attributes
	 *   \s*          - optional whitespace
	 *   (https?:\/\/[^\s<>"]+)  - URL as visible text (captured)
	 *   \s*          - optional whitespace
	 *   <\/a>        - closing tag
	 *
	 * The `gi` flags make it global (find all matches) and case-insensitive.
	 * We reset lastIndex before each use to prevent state pollution across calls.
	 */
	var NAKED_URL_LINK_RE = /<a\b[^>]*>\s*(https?:\/\/[^\s<>"]+)\s*<\/a>/gi;

	/* ------------------------------------------------------------------ */
	/* Main panel component                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * LivqaceaPrePublishCheck - functional React component rendered by registerPlugin.
	 *
	 * Reads block state via useSelect (reactive) and returns a
	 * PluginPrePublishPanel with the current list of accessibility issues.
	 *
	 * @returns {wp.element.Element}
	 */
	function LivqaceaPrePublishCheck() {

		/* Reactive block state - re-evaluates whenever any block changes. */
		var blocks = useSelect( function ( select ) {
			return select( 'core/block-editor' ).getBlocks();
		} );

		var allBlocks = flattenBlocks( blocks );
		var issues    = [];

		/* -------- Check A: core/image blocks without alt text ----------- */

		var imagesWithoutAlt = 0;

		allBlocks.forEach( function ( block ) {
			if ( block.name === 'core/image' ) {
				var alt = block.attributes && block.attributes.alt;
				if ( ! alt || alt.trim() === '' ) {
					imagesWithoutAlt++;
				}
			}
		} );

		if ( imagesWithoutAlt > 0 ) {
			issues.push( {
				key:  'img-alt',
				icon: '🖼️',
				text: imagesWithoutAlt === 1
					? __( '1 image missing alternative text (alt). Screen reader users will not be able to perceive the image content (WCAG 1.1.1).', 'livq-accessfix' )
					: imagesWithoutAlt + ' ' + __( 'images missing alternative text (alt). Screen reader users will not be able to perceive the content (WCAG 1.1.1).', 'livq-accessfix' ),
			} );
		}

		/* -------- Check B: links with naked URL as visible text --------- */

		var nakedLinkCount = 0;

		allBlocks.forEach( function ( block ) {
			var content = block.attributes && block.attributes.content;
			if ( typeof content !== 'string' || content.indexOf( 'href' ) === -1 ) {
				return;
			}

			NAKED_URL_LINK_RE.lastIndex = 0; // Always reset before exec loop.
			while ( NAKED_URL_LINK_RE.exec( content ) !== null ) {
				nakedLinkCount++;
			}
		} );

		if ( nakedLinkCount > 0 ) {
			issues.push( {
				key:  'naked-url',
				icon: '🔗',
				text: nakedLinkCount === 1
					? __( '1 link uses a URL as clickable text. Replace the URL with a meaningful description of the action or destination (WCAG 2.4.4).', 'livq-accessfix' )
					: nakedLinkCount + ' ' + __( 'links use a URL as clickable text. Replace them with meaningful descriptions (WCAG 2.4.4).', 'livq-accessfix' ),
			} );
		}

		/* -------- Panel title changes depending on result --------------- */

		var panelTitle = issues.length === 0
			? __( 'Accessibility - No issues', 'livq-accessfix' )
			: __( 'Accessibility', 'livq-accessfix' ) + ' - ' + issues.length + ' ' +
			  ( issues.length === 1
			    ? __( 'warning', 'livq-accessfix' )
			    : __( 'warnings', 'livq-accessfix' ) );

		/* -------- Render ------------------------------------------------ */

		return createElement(
			PluginPrePublishPanel,
			{
				title:       panelTitle,
				icon:        'universal-access-alt',
				initialOpen: issues.length > 0, // Auto-expand if there are issues.
			},

			issues.length === 0

				/* All-clear message */
				? createElement(
					'p',
					{
						style: {
							display:    'flex',
							alignItems: 'center',
							gap:        '8px',
							margin:     '4px 0',
							color:      '#1e8a4c',
							fontWeight: '600',
							fontSize:   '13px',
						},
					},
					createElement( 'span', { style: { fontSize: '16px' } }, '✓' ),
					__( 'No accessibility issues detected in this document.', 'livq-accessfix' )
				)

				/* Issue list */
				: createElement(
					'ul',
					{
						style: {
							margin:      '4px 0',
							paddingLeft: '0',
							listStyle:   'none',
						},
					},
					issues.map( function ( issue, idx ) {
						return createElement(
							'li',
							{
								key:   issue.key,
								style: {
									display:      'flex',
									alignItems:   'flex-start',
									gap:          '10px',
									padding:      '10px 0',
									borderBottom: idx < issues.length - 1
									              ? '1px solid #f0f0f1'
									              : 'none',
									fontSize:     '13px',
									lineHeight:   '1.5',
									color:        '#3c434a',
								},
							},
							/* Icon column */
							createElement(
								'span',
								{
									'aria-hidden': 'true',
									style: {
										flexShrink: '0',
										fontSize:   '18px',
										marginTop:  '1px',
									},
								},
								issue.icon
							),
							/* Text column */
							createElement( 'span', null, issue.text )
						);
					} )
				)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register the plugin with Gutenberg                                   */
	/* ------------------------------------------------------------------ */

	registerPlugin( 'livqacea-prepublish-check', {
		render: LivqaceaPrePublishCheck,
	} );

}() );
