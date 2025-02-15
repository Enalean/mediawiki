<?php

namespace MediaWiki\Search\SearchWidgets;

use Html;
use ILanguageConverter;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Widget\SearchInputWidget;
use NamespaceInfo;
use SearchEngineConfig;
use SpecialSearch;
use Xml;

class SearchFormWidget {
	/** @var SpecialSearch */
	protected $specialSearch;
	/** @var SearchEngineConfig */
	protected $searchConfig;
	/** @var array */
	protected $profiles;
	/** @var HookContainer */
	private $hookContainer;
	/** @var HookRunner */
	private $hookRunner;
	/** @var ILanguageConverter */
	private $languageConverter;
	/** @var NamespaceInfo */
	private $namespaceInfo;

	/**
	 * @param SpecialSearch $specialSearch
	 * @param SearchEngineConfig $searchConfig
	 * @param HookContainer $hookContainer
	 * @param ILanguageConverter $languageConverter
	 * @param NamespaceInfo $namespaceInfo
	 * @param array $profiles
	 */
	public function __construct(
		SpecialSearch $specialSearch,
		SearchEngineConfig $searchConfig,
		HookContainer $hookContainer,
		ILanguageConverter $languageConverter,
		NamespaceInfo $namespaceInfo,
		array $profiles
	) {
		$this->specialSearch = $specialSearch;
		$this->searchConfig = $searchConfig;
		$this->hookContainer = $hookContainer;
		$this->hookRunner = new HookRunner( $hookContainer );
		$this->languageConverter = $languageConverter;
		$this->namespaceInfo = $namespaceInfo;
		$this->profiles = $profiles;
	}

	/**
	 * @param string $profile The current search profile
	 * @param string $term The current search term
	 * @param int $numResults The number of results shown
	 * @param int $totalResults The total estimated results found
	 * @param int $offset Current offset in search results
	 * @param bool $isPowerSearch Is the 'advanced' section open?
	 * @param array $options Widget options
	 * @return string HTML
	 */
	public function render(
		$profile,
		$term,
		$numResults,
		$totalResults,
		$offset,
		$isPowerSearch,
		array $options = []
	) {
		$user = $this->specialSearch->getUser();

		$form = Xml::openElement(
				'form',
				[
					'id' => $isPowerSearch ? 'powersearch' : 'search',
					// T151903: default to POST in case JS is disabled
					'method' => ( $isPowerSearch && $user->isRegistered() ) ? 'post' : 'get',
					'action' => wfScript(),
				]
			) .
				Html::rawElement(
					'div',
					[ 'id' => 'mw-search-top-table' ],
					$this->shortDialogHtml( $profile, $term, $numResults, $totalResults, $offset, $options )
				) .
				Html::rawElement( 'div', [ 'class' => 'mw-search-visualclear' ] ) .
				Html::rawElement(
					'div',
					[ 'class' => 'mw-search-profile-tabs' ],
					$this->profileTabsHtml( $profile, $term ) .
						Html::rawElement( 'div', [ 'style' => 'clear:both' ] )
				) .
				$this->optionsHtml( $term, $isPowerSearch, $profile ) .
			Xml::closeElement( 'form' );

		return Html::rawElement( 'div', [ 'class' => 'mw-search-form-wrapper' ], $form );
	}

	/**
	 * @param string $profile The current search profile
	 * @param string $term The current search term
	 * @param int $numResults The number of results shown
	 * @param int $totalResults The total estimated results found
	 * @param int $offset Current offset in search results
	 * @param array $options Widget options
	 * @return string HTML
	 */
	protected function shortDialogHtml(
		$profile,
		$term,
		$numResults,
		$totalResults,
		$offset,
		array $options = []
	) {
		$searchWidget = new SearchInputWidget( $options + [
			'id' => 'searchText',
			'name' => 'search',
			'autofocus' => trim( $term ) === '',
			'title' => $this->specialSearch->msg( 'searchsuggest-search' )->text(),
			'value' => $term,
			'dataLocation' => 'content',
			'infusable' => true,
		] );

		$html = new \OOUI\ActionFieldLayout( $searchWidget, new \OOUI\ButtonInputWidget( [
			'type' => 'submit',
			'label' => $this->specialSearch->msg( 'searchbutton' )->text(),
			'flags' => [ 'progressive', 'primary' ],
		] ), [
			'align' => 'top',
		] );

		if ( $this->specialSearch->getPrefix() !== '' ) {
			$html .= Html::hidden( 'prefix', $this->specialSearch->getPrefix() );
		}

		if ( $totalResults > 0 && $offset < $totalResults ) {
			$html .= Xml::tags(
				'div',
				[
					'class' => 'results-info',
					'data-mw-num-results-offset' => $offset,
					'data-mw-num-results-total' => $totalResults
				],
				$this->specialSearch->msg( 'search-showingresults' )
					->numParams( $offset + 1, $offset + $numResults, $totalResults )
					->numParams( $numResults )
					->parse()
			);
		}

		$html .=
			Html::hidden( 'title', $this->specialSearch->getPageTitle()->getPrefixedText() ) .
			Html::hidden( 'profile', $profile ) .
			Html::hidden( 'fulltext', '1' );

		return $html;
	}

	/**
	 * Generates HTML for the list of available search profiles.
	 *
	 * @param string $profile The currently selected profile
	 * @param string $term The user provided search terms
	 * @return string HTML
	 */
	protected function profileTabsHtml( $profile, $term ) {
		$bareterm = $this->startsWithImage( $term )
			? substr( $term, strpos( $term, ':' ) + 1 )
			: $term;
		$lang = $this->specialSearch->getLanguage();
		$items = [];
		foreach ( $this->profiles as $id => $profileConfig ) {
			$profileConfig['parameters']['profile'] = $id;
			$tooltipParam = isset( $profileConfig['namespace-messages'] )
				? $lang->commaList( $profileConfig['namespace-messages'] )
				: null;
			$items[] = Xml::tags(
				'li',
				[ 'class' => $profile === $id ? 'current' : 'normal' ],
				$this->makeSearchLink(
					$bareterm,
					$this->specialSearch->msg( $profileConfig['message'] )->text(),
					$this->specialSearch->msg( $profileConfig['tooltip'], $tooltipParam )->text(),
					$profileConfig['parameters']
				)
			);
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'search-types' ],
			Html::rawElement( 'ul', [], implode( '', $items ) )
		);
	}

	/**
	 * Check if query starts with image: prefix
	 *
	 * @param string $term The string to check
	 * @return bool
	 */
	protected function startsWithImage( $term ) {
		$parts = explode( ':', $term );
		return count( $parts ) > 1
			&& $this->specialSearch->getContentLanguage()->getNsIndex( $parts[0] ) === NS_FILE;
	}

	/**
	 * Make a search link with some target namespaces
	 *
	 * @param string $term The term to search for
	 * @param string $label Link's text
	 * @param string $tooltip Link's tooltip
	 * @param array $params Query string parameters
	 * @return string HTML fragment
	 */
	protected function makeSearchLink( $term, $label, $tooltip, array $params = [] ) {
		$params += [
			'search' => $term,
			'fulltext' => 1,
		];

		return Xml::element(
			'a',
			[
				'href' => $this->specialSearch->getPageTitle()->getLocalURL( $params ),
				'title' => $tooltip,
			],
			$label
		);
	}

	/**
	 * Generates HTML for advanced options available with the currently
	 * selected search profile.
	 *
	 * @param string $term User provided search term
	 * @param bool $isPowerSearch Is the advanced search profile enabled?
	 * @param string $profile The current search profile
	 * @return string HTML
	 */
	protected function optionsHtml( $term, $isPowerSearch, $profile ) {
		if ( $isPowerSearch ) {
			$html = $this->powerSearchBox( $term, [] );
		} else {
			$html = '';
			$this->getHookRunner()->onSpecialSearchProfileForm(
				$this->specialSearch, $html, $profile, $term, []
			);
		}

		return $html;
	}

	/**
	 * @param string $term The current search term
	 * @param array $opts Additional key/value pairs that will be submitted
	 *  with the generated form.
	 * @return string HTML
	 */
	protected function powerSearchBox( $term, array $opts ) {
		$rows = [];
		$activeNamespaces = $this->specialSearch->getNamespaces();
		foreach ( $this->searchConfig->searchableNamespaces() as $namespace => $name ) {
			$subject = $this->namespaceInfo->getSubject( $namespace );
			if ( !isset( $rows[$subject] ) ) {
				$rows[$subject] = "";
			}

			$name = $this->languageConverter->convertNamespace( $namespace );
			if ( $name === '' ) {
				$name = $this->specialSearch->msg( 'blanknamespace' )->text();
			}

			$rows[$subject] .= Html::rawElement(
				'td',
				[],
				Xml::checkLabel(
					$name,
					"ns{$namespace}",
					"mw-search-ns{$namespace}",
					in_array( $namespace, $activeNamespaces )
				)
			);
		}

		// Lays out namespaces in multiple floating two-column tables so that they'll
		// be arranged nicely while still accommodating different screen widths
		$tableRows = [];
		foreach ( $rows as $row ) {
			$tableRows[] = Html::rawElement( 'tr', [], $row );
		}
		$namespaceTables = [];
		foreach ( array_chunk( $tableRows, 4 ) as $chunk ) {
			$namespaceTables[] = implode( '', $chunk );
		}

		$showSections = [
			'namespaceTables' => "<table>" . implode( '</table><table>', $namespaceTables ) . '</table>',
		];
		$this->getHookRunner()->onSpecialSearchPowerBox( $showSections, $term, $opts );

		$hidden = '';
		foreach ( $opts as $key => $value ) {
			$hidden .= Html::hidden( $key, $value );
		}

		$divider = Html::rawElement( 'div', [ 'class' => 'divider' ], '' );

		// Stuff to feed SpecialSearch::saveNamespaces()
		$user = $this->specialSearch->getUser();
		$remember = '';
		if ( $user->isRegistered() ) {
			$remember = $divider . Xml::checkLabel(
				$this->specialSearch->msg( 'powersearch-remember' )->text(),
				'nsRemember',
				'mw-search-powersearch-remember',
				false,
				// The token goes here rather than in a hidden field so it
				// is only sent when necessary (not every form submission)
				[ 'value' => $user->getEditToken(
					'searchnamespace',
					$this->specialSearch->getRequest()
				) ]
			);
		}

		// Temporary variables to reduce nesting needed
		$toggleBoxContents =
			Html::rawElement( 'label', [], $this->specialSearch->msg( 'powersearch-togglelabel' )->escaped() ) .
			Html::rawElement(
				'input',
				[
					'type' => 'button',
					'id' => 'mw-search-toggleall',
					'value' => $this->specialSearch->msg( 'powersearch-toggleall' )->text(),
				]
			) .
			Html::rawElement(
				'input',
				[
					'type' => 'button',
					'id' => 'mw-search-togglenone',
					'value' => $this->specialSearch->msg( 'powersearch-togglenone' )->text(),
				]
			);
		$fieldSetContents =
			Html::rawElement( 'legend', [], $this->specialSearch->msg( 'powersearch-legend' )->escaped() ) .
			Html::rawElement( 'h4', [], $this->specialSearch->msg( 'powersearch-ns' )->parse() ) .
			// Handled by JavaScript if available
			Html::rawElement(
				'div',
				[ 'id' => 'mw-search-togglebox' ],
				$toggleBoxContents
			) .
			$divider . implode( $divider, $showSections ) . $hidden . $remember;

		return Html::rawElement( 'fieldset', [ 'id' => 'mw-searchoptions' ], $fieldSetContents );
	}

	/**
	 * @since 1.35
	 * @return HookContainer
	 */
	protected function getHookContainer() {
		return $this->hookContainer;
	}

	/**
	 * @internal This is for use by core only. Hook interfaces may be removed
	 *   without notice.
	 * @since 1.35
	 * @return HookRunner
	 */
	protected function getHookRunner() {
		return $this->hookRunner;
	}
}
