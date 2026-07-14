( function () {
	'use strict';

	var panelId = 'scai-ticket-ai-panel';
	var latestResultText = '';
	var refreshTimer = null;
	var mutationObserver = null;
	var historyPatched = false;
	var initialized = false;

	function getConfig() {
		var config = window.scaiTicketAI || {};

		return {
			ajaxUrl: typeof config.ajaxUrl === 'string' ? config.ajaxUrl : '',
			nonce: typeof config.nonce === 'string' ? config.nonce : '',
			strings: config.strings && typeof config.strings === 'object' ? config.strings : {},
			debug: config.debug === true
		};
	}

	function getString( key, fallback ) {
		var config = getConfig();

		return typeof config.strings[ key ] === 'string' ? config.strings[ key ] : fallback;
	}

	function isIndividualTicketView() {
		return getCurrentViewType() === 'ticket';
	}

	function isSupportCandyTicketModule() {
		if ( isBackendScreen() ) {
			return String( getQueryParam( 'page' ) || '' ).toLowerCase() === 'wpsc-tickets';
		}

		return String( getQueryParam( 'wpsc-section' ) || '' ).toLowerCase() === 'ticket-list';
	}

	function getCurrentViewType() {
		return getTicketViewState().viewType;
	}

	function getTicketViewState() {
		var page = getQueryParam( 'page' );
		var wpscSection = getQueryParam( 'wpsc-section' );
		var backendTicketId = getBackendTicketIdFromUrl();
		var frontendTicketId = getFrontendTicketIdFromUrl();
		var detection = detectTicketIdWithConfidence();
		var hasTicketId = detection.id > 0;
		var hasDetailEvidence = hasTicketDetailEvidence();
		var hasListEvidence = hasTicketListEvidence();
		var viewState;

		page = String( page || '' ).toLowerCase();
		wpscSection = String( wpscSection || '' ).toLowerCase();

		if ( 'wpsc-settings' === page ) {
			viewState = createTicketViewState( 'other', false, detection );
			debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
			return viewState;
		}

		if ( ! isSupportCandyTicketModule() ) {
			viewState = createTicketViewState( 'other', false, detection );
			debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
			return viewState;
		}

		if ( isBackendScreen() ) {
			if ( backendTicketId ) {
				detection = createDetectionResult( backendTicketId, 'high', 'backend_url' );
				viewState = createTicketViewState( 'ticket', false, detection );
				debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
				return viewState;
			}

			if ( hasTicketId && hasDetailEvidence ) {
				viewState = createTicketViewState( 'ticket', false, detection );
				debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
				return viewState;
			}

			viewState = createTicketViewState( 'list', false, detection );
			debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
			return viewState;
		}

		if ( 'ticket-list' === wpscSection ) {
			if ( frontendTicketId ) {
				viewState = createTicketViewState( 'ticket', true, createDetectionResult( frontendTicketId, 'high', 'frontend_url' ) );
				debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
				return viewState;
			}

			if ( hasTicketId && ( hasDetailEvidence || ! hasListEvidence ) ) {
				viewState = createTicketViewState( 'ticket', true, detection );
				debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
				return viewState;
			}

			if ( hasDetailEvidence && ! hasListEvidence ) {
				viewState = createTicketViewState( 'ticket', true, detection );
				debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
				return viewState;
			}

			viewState = createTicketViewState( 'list', false, detection );
			debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
			return viewState;
		}

		viewState = createTicketViewState( 'other', false, detection );
		debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence );
		return viewState;
	}

	function createTicketViewState( viewType, allowManualInput, detection ) {
		return {
			viewType: viewType || 'other',
			isIndividual: viewType === 'ticket',
			allowManualInput: !! allowManualInput,
			detection: detection || createDetectionResult( 0, '', '' )
		};
	}

	function debugRouteDecision( viewState, hasDetailEvidence, hasListEvidence ) {
		if ( ! getConfig().debug || ! window.console || typeof window.console.log !== 'function' ) {
			return;
		}

		window.console.log( 'SCAI ticket route', {
			viewType: viewState.viewType,
			ticketId: viewState.detection.id,
			source: viewState.detection.source,
			hasDetailEvidence: !! hasDetailEvidence,
			hasListEvidence: !! hasListEvidence,
			url: window.location.href
		} );
	}

	function isBackendScreen() {
		return !! ( document.body && document.body.classList && document.body.classList.contains( 'wp-admin' ) );
	}

	function isFrontendScreen() {
		return ! isBackendScreen();
	}

	function hasTicketDetailEvidence() {
		var selectors = [
			'textarea[name*="reply"]',
			'textarea[name*="description"]',
			'[contenteditable="true"]',
			'.wpsc-thread',
			'.wpsc-ticket-thread',
			'.wpsc-ticket-details',
			'.wpsc-ticket-container',
			'.wpsc-ticket-body',
			'.wpsc-reply',
			'.wpsc-editor',
			'.wpsc-it-body',
			'.wpsc-it-thread',
			'.wpsc-it-reply',
			'.tox-tinymce',
			'.wp-editor-wrap',
			'#wpsc-individual-ticket',
			'.wpsc-it-container',
			'.wpsc-individual-ticket'
		];

		return hasVisibleElement( selectors );
	}

	function hasTicketListEvidence() {
		var selectors = [
			'.wpsc-ticket-list',
			'#wpsc-ticket-list',
			'.wpsc-tickets',
			'.wpsc-tickets-list',
			'.wpsc-ticket-list-table',
			'.wpsc-list',
			'.wpsc-card',
			'table'
		];

		return hasVisibleElement( selectors );
	}

	function hasVisibleElement( selectors ) {
		var index;
		var elements;
		var elementIndex;
		var element;

		for ( index = 0; index < selectors.length; index++ ) {
			elements = document.querySelectorAll( selectors[ index ] );

			for ( elementIndex = 0; elementIndex < elements.length; elementIndex++ ) {
				element = elements[ elementIndex ];

				if ( isPluginElement( element ) || ! isElementVisible( element ) ) {
					continue;
				}

				return true;
			}
		}

		return false;
	}

	function getTicketId() {
		var result = detectTicketIdWithConfidence();

		return result.confidence === 'high' ? result.id : 0;
	}

	function detectTicketId() {
		return detectTicketIdWithConfidence().id;
	}

	function detectTicketIdWithConfidence() {
		var backendUrlId = isBackendTicketRoute() ? getBackendTicketIdFromUrl() : 0;
		var frontendUrlId = getFrontendTicketIdFromUrl();
		var commonUrlId = getCommonTicketIdFromUrl();
		var inputId;
		var dataAttributeId;

		if ( backendUrlId ) {
			return createDetectionResult( backendUrlId, 'high', 'backend_url' );
		}

		if ( frontendUrlId ) {
			return createDetectionResult( frontendUrlId, 'high', 'frontend_url' );
		}

		if ( commonUrlId ) {
			return createDetectionResult( commonUrlId, 'high', 'url' );
		}

		inputId = getHighConfidenceTicketIdFromInputs();

		if ( inputId ) {
			return createDetectionResult( inputId, 'high', 'input' );
		}

		dataAttributeId = getHighConfidenceTicketIdFromDataAttributes();

		if ( dataAttributeId ) {
			return createDetectionResult( dataAttributeId, 'high', 'data_attribute' );
		}

		return createDetectionResult( 0, '', '' );
	}

	function createDetectionResult( id, confidence, source ) {
		return {
			id: parseTicketId( id ),
			confidence: confidence || '',
			source: source || ''
		};
	}

	function getHighConfidenceTicketId() {
		return ( isBackendTicketRoute() ? getBackendTicketIdFromUrl() : 0 ) ||
			getFrontendTicketIdFromUrl() ||
			getCommonTicketIdFromUrl() ||
			getHighConfidenceTicketIdFromInputs() ||
			getHighConfidenceTicketIdFromDataAttributes();
	}

	function isBackendTicketRoute() {
		return String( getQueryParam( 'page' ) || '' ).toLowerCase() === 'wpsc-tickets' &&
			String( getQueryParam( 'section' ) || '' ).toLowerCase() === 'ticket-list';
	}

	function getBackendTicketIdFromUrl() {
		return parseTicketId( getQueryParam( 'id' ) );
	}

	function getFrontendTicketIdFromUrl() {
		return parseTicketId( getQueryParam( 'ticket-id' ) );
	}

	function getCommonTicketIdFromUrl() {
		var keys = [ 'ticket_id', 'wpsc_ticket_id' ];
		var index;
		var value;

		for ( index = 0; index < keys.length; index++ ) {
			value = parseTicketId( getQueryParam( keys[ index ] ) );

			if ( value ) {
				return value;
			}
		}

		return 0;
	}

	function getTicketIdFromUrl() {
		return getHighConfidenceTicketId();
	}

	function getQueryParam( key ) {
		var params;
		var pairs;
		var index;
		var pair;

		if ( typeof window.URLSearchParams === 'function' ) {
			params = new URLSearchParams( window.location.search );
			return params.get( key );
		}

		pairs = window.location.search.replace( /^\?/, '' ).split( '&' );

		for ( index = 0; index < pairs.length; index++ ) {
			pair = pairs[ index ].split( '=' );

			if ( decodeURIComponent( pair[0] || '' ) === key ) {
				return decodeURIComponent( pair[1] || '' );
			}
		}

		return '';
	}

	function getTicketIdFromInputs() {
		return getHighConfidenceTicketIdFromInputs();
	}

	function getHighConfidenceTicketIdFromInputs() {
		var selectors = [
			'input[name="ticket_id"]',
			'input[name="wpsc_ticket_id"]'
		];
		var index;
		var elements;
		var elementIndex;
		var element;
		var value;

		for ( index = 0; index < selectors.length; index++ ) {
			elements = document.querySelectorAll( selectors[ index ] );

			for ( elementIndex = 0; elementIndex < elements.length; elementIndex++ ) {
				element = elements[ elementIndex ];

				if ( isPluginElement( element ) ) {
					continue;
				}

				value = parseTicketId( element.value );

				if ( value ) {
					return value;
				}
			}
		}

		return 0;
	}

	function getTicketIdFromDataAttributes() {
		return getHighConfidenceTicketIdFromDataAttributes();
	}

	function getHighConfidenceTicketIdFromDataAttributes() {
		var selectors = [
			'[data-ticket-id]',
			'[data-wpsc-ticket-id]'
		];
		var attributes = [ 'data-ticket-id', 'data-wpsc-ticket-id' ];
		var index;
		var elements;
		var elementIndex;
		var element;
		var bodyId;
		var htmlId;
		var elementId;

		for ( index = 0; index < selectors.length; index++ ) {
			elements = document.querySelectorAll( selectors[ index ] );

			for ( elementIndex = 0; elementIndex < elements.length; elementIndex++ ) {
				element = elements[ elementIndex ];

				if ( isPluginElement( element ) ) {
					continue;
				}

				elementId = parseTicketId( element.getAttribute( attributes[ index ] ) );

				if ( elementId ) {
					return elementId;
				}
			}
		}

		bodyId = getTicketIdFromElementAttributes( document.body, attributes );
		htmlId = getTicketIdFromElementAttributes( document.documentElement, attributes );

		return bodyId || htmlId || 0;
	}

	function getTicketIdFromElementAttributes( element, attributes ) {
		var index;
		var value;

		if ( ! element ) {
			return 0;
		}

		for ( index = 0; index < attributes.length; index++ ) {
			value = parseTicketId( element.getAttribute( attributes[ index ] ) );

			if ( value ) {
				return value;
			}
		}

		return 0;
	}

	function parseTicketId( value ) {
		var parsed = parseInt( value, 10 );

		return isFinite( parsed ) && parsed > 0 ? parsed : 0;
	}

	function findMountPoint() {
		var selectors = [
			'#wpsc-individual-ticket',
			'.wpsc-it-container',
			'.wpsc-ticket-container',
			'.wpsc-ticket-list',
			'#wpsc-container',
			'.wpsc-container',
			'.wpsc-ticket',
			'.wpsc-body',
			'.supportcandy',
			'.entry-content',
			'main',
			'#primary',
			'#content'
		];
		var index;
		var element;

		for ( index = 0; index < selectors.length; index++ ) {
			element = document.querySelector( selectors[ index ] );

			if ( element ) {
				return element;
			}
		}

		return document.querySelector( '#wpbody-content' ) || document.querySelector( '.wrap' ) || document.body;
	}

	function createPanel( detection, viewState ) {
		var existing = document.getElementById( panelId );
		var ticketId = detection && detection.id ? detection.id : 0;
		var confidence = detection && detection.confidence ? detection.confidence : '';
		var showManualInput = !! ( viewState && viewState.allowManualInput ) || confidence !== 'high';
		var panel;
		var title;
		var status;
		var manualWrap;
		var manualLabel;
		var manualInput;
		var actions;
		var summaryButton;
		var replyButton;
		var draftLabel;
		var draft;
		var improveButton;
		var output;
		var resultActions;
		var copyButton;
		var insertButton;
		var message;
		var mountPoint;

		if ( existing ) {
			return existing;
		}

		panel = document.createElement( 'section' );
		panel.id = panelId;
		panel.className = 'scai-ticket-ai-panel';
		panel.setAttribute( 'data-ticket-id', confidence === 'high' ? String( ticketId ) : '' );
		panel.setAttribute( 'data-ticket-confidence', confidence );
		panel.style.margin = '16px 0';
		panel.style.padding = '12px';
		panel.style.border = '1px solid #c3c4c7';
		panel.style.background = '#fff';

		title = document.createElement( 'h2' );
		title.className = 'scai-ticket-ai-title';
		title.textContent = getString( 'panelTitle', 'SupportCandy AI' );

		status = document.createElement( 'p' );
		status.className = 'scai-ticket-ai-status';

		if ( ticketId && confidence === 'high' ) {
			status.textContent = 'Detected Ticket ID: ' + ticketId;
		} else {
			status.textContent = 'Enter the internal SupportCandy Ticket ID to use AI.';
		}

		manualWrap = document.createElement( 'div' );
		manualWrap.className = 'scai-manual-ticket-id-wrap';

		if ( showManualInput ) {
			manualLabel = document.createElement( 'label' );
			manualLabel.className = 'scai-manual-ticket-id-label';
			manualLabel.setAttribute( 'for', 'scai-manual-ticket-id' );
			manualLabel.textContent = 'Internal Ticket ID';

			manualInput = document.createElement( 'input' );
			manualInput.type = 'number';
			manualInput.min = '1';
			manualInput.id = 'scai-manual-ticket-id';
			manualInput.className = 'scai-manual-ticket-id';
			manualInput.inputMode = 'numeric';
			manualInput.placeholder = confidence === 'high' ? 'Override detected ticket ID' : 'Enter internal ticket ID';

			manualWrap.appendChild( manualLabel );
			manualWrap.appendChild( manualInput );
		}

		actions = document.createElement( 'div' );
		actions.className = 'scai-ticket-ai-actions';

		summaryButton = createButton( 'scai-generate-summary', getString( 'generateSummary', 'Generate Summary' ) );
		replyButton = createButton( 'scai-generate-reply', getString( 'generateReply', 'Generate Reply' ) );

		actions.appendChild( summaryButton );
		actions.appendChild( replyButton );

		draftLabel = document.createElement( 'label' );
		draftLabel.className = 'scai-draft-label';
		draftLabel.setAttribute( 'for', 'scai-draft-reply' );
		draftLabel.textContent = getString( 'draftLabel', 'Draft Reply' );

		draft = document.createElement( 'textarea' );
		draft.id = 'scai-draft-reply';
		draft.className = 'scai-draft-reply';
		draft.rows = 5;

		improveButton = createButton( 'scai-improve-draft', getString( 'improveDraft', 'Improve Draft' ) );

		output = document.createElement( 'pre' );
		output.className = 'scai-ticket-ai-output';
		output.setAttribute( 'aria-live', 'polite' );

		resultActions = document.createElement( 'div' );
		resultActions.className = 'scai-result-actions';
		resultActions.style.display = 'none';

		copyButton = createButton( 'scai-copy-result', 'Copy Result' );
		insertButton = createButton( 'scai-insert-result', 'Insert into Reply Editor' );

		resultActions.appendChild( copyButton );
		resultActions.appendChild( insertButton );

		message = document.createElement( 'p' );
		message.className = 'scai-ticket-ai-message';
		message.setAttribute( 'aria-live', 'polite' );

		panel.appendChild( title );
		panel.appendChild( status );
		panel.appendChild( manualWrap );
		panel.appendChild( actions );
		panel.appendChild( draftLabel );
		panel.appendChild( draft );
		panel.appendChild( improveButton );
		panel.appendChild( output );
		panel.appendChild( resultActions );
		panel.appendChild( message );

		mountPoint = findMountPoint();

		if ( mountPoint.firstChild ) {
			mountPoint.insertBefore( panel, mountPoint.firstChild );
		} else {
			mountPoint.appendChild( panel );
		}

		return panel;
	}

	function updatePanelState( panel, detection, viewState ) {
		var ticketId = detection && detection.id ? detection.id : 0;
		var confidence = detection && detection.confidence ? detection.confidence : '';
		var status = panel ? panel.querySelector( '.scai-ticket-ai-status' ) : null;
		var manualWrap = panel ? panel.querySelector( '.scai-manual-ticket-id-wrap' ) : null;
		var manualInput = panel ? panel.querySelector( '.scai-manual-ticket-id' ) : null;
		var showManualInput = !! ( viewState && viewState.allowManualInput ) || confidence !== 'high';

		if ( ! panel ) {
			return;
		}

		panel.setAttribute( 'data-ticket-id', confidence === 'high' ? String( ticketId ) : '' );
		panel.setAttribute( 'data-ticket-confidence', confidence );

		if ( status ) {
			if ( ticketId && confidence === 'high' ) {
				setElementText( status, 'Detected Ticket ID: ' + ticketId );
			} else {
				setElementText( status, 'Enter the internal SupportCandy Ticket ID to use AI.' );
			}
		}

		if ( showManualInput && manualWrap && ! manualInput ) {
			addManualTicketIdInput( manualWrap, confidence );
		}

		if ( ! showManualInput && manualWrap && manualWrap.firstChild ) {
			manualWrap.textContent = '';
		}
	}

	function setElementText( element, text ) {
		if ( element && element.textContent !== text ) {
			element.textContent = text;
		}
	}

	function addManualTicketIdInput( manualWrap, confidence ) {
		var manualLabel;
		var manualInput;

		if ( ! manualWrap ) {
			return;
		}

		manualLabel = document.createElement( 'label' );
		manualLabel.className = 'scai-manual-ticket-id-label';
		manualLabel.setAttribute( 'for', 'scai-manual-ticket-id' );
		manualLabel.textContent = 'Internal Ticket ID';

		manualInput = document.createElement( 'input' );
		manualInput.type = 'number';
		manualInput.min = '1';
		manualInput.id = 'scai-manual-ticket-id';
		manualInput.className = 'scai-manual-ticket-id';
		manualInput.inputMode = 'numeric';
		manualInput.placeholder = confidence === 'high' ? 'Override detected ticket ID' : 'Enter internal ticket ID';

		manualWrap.appendChild( manualLabel );
		manualWrap.appendChild( manualInput );
	}

	function createButton( className, text ) {
		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'button ' + className;
		button.textContent = text;

		return button;
	}

	function bindEvents( panel ) {
		var summaryButton;
		var replyButton;
		var improveButton;
		var copyButton;
		var insertButton;
		var draft;
		var output;

		if ( ! panel || panel.getAttribute( 'data-scai-events-bound' ) === '1' ) {
			return;
		}

		summaryButton = panel.querySelector( '.scai-generate-summary' );
		replyButton = panel.querySelector( '.scai-generate-reply' );
		improveButton = panel.querySelector( '.scai-improve-draft' );
		copyButton = panel.querySelector( '.scai-copy-result' );
		insertButton = panel.querySelector( '.scai-insert-result' );
		draft = panel.querySelector( '.scai-draft-reply' );
		output = panel.querySelector( '.scai-ticket-ai-output' );

		panel.setAttribute( 'data-scai-events-bound', '1' );

		if ( summaryButton ) {
			summaryButton.addEventListener( 'click', function () {
				runAction( 'scai_generate_ticket_summary', panel, '', summaryButton, output );
			} );
		}

		if ( replyButton ) {
			replyButton.addEventListener( 'click', function () {
				runAction( 'scai_generate_ticket_reply', panel, '', replyButton, output );
			} );
		}

		if ( improveButton ) {
			improveButton.addEventListener( 'click', function () {
				runAction( 'scai_improve_ticket_reply', panel, draft ? draft.value : '', improveButton, output );
			} );
		}

		if ( copyButton ) {
			copyButton.addEventListener( 'click', function () {
				var text = getLatestResultText();

				if ( ! text ) {
					showPanelMessage( 'No AI result is available to copy.', 'error' );
					return;
				}

				copyToClipboard( text ).then( function () {
					showPanelMessage( 'AI result copied to clipboard.', 'success' );
				}, function () {
					showPanelMessage( 'Unable to copy result. Please copy it manually.', 'error' );
				} );
			} );
		}

		if ( insertButton ) {
			insertButton.addEventListener( 'click', function () {
				var text = getLatestResultText();

				if ( ! text ) {
					showPanelMessage( 'No AI result is available to insert.', 'error' );
					return;
				}

				if ( insertIntoReplyEditor( text ) ) {
					showPanelMessage( 'AI result inserted into the reply editor. Review before submitting.', 'success' );
					return;
				}

				showPanelMessage( 'Reply editor not found. Please copy the result manually.', 'error' );
			} );
		}
	}

	function getManualTicketId( panel ) {
		var input = panel ? panel.querySelector( '.scai-manual-ticket-id' ) : null;

		return input ? parseTicketId( input.value ) : 0;
	}

	function resolveTicketId( panel ) {
		var manual = getManualTicketId( panel );
		var panelIdValue = panel ? parseTicketId( panel.getAttribute( 'data-ticket-id' ) ) : 0;
		var detected = detectTicketIdWithConfidence();

		if ( manual ) {
			return manual;
		}

		if ( detected.confidence === 'high' && detected.id ) {
			return detected.id;
		}

		if ( panelIdValue ) {
			return panelIdValue;
		}

		return 0;
	}

	function runAction( action, panel, replyText, button, output ) {
		var config = getConfig();
		var ticketId = resolveTicketId( panel );
		var payload;

		if ( ! config.ajaxUrl || ! config.nonce ) {
			showError( output, getString( 'missingConfig', 'AI actions are not available on this screen.' ) );
			return;
		}

		if ( ! ticketId ) {
			showError( output, 'Please enter the internal Ticket ID.' );
			return;
		}

		if ( action === 'scai_improve_ticket_reply' && ! String( replyText || '' ).trim() ) {
			showError( output, getString( 'missingDraft', 'Enter a draft reply before improving it.' ) );
			return;
		}

		payload = {
			action: action,
			nonce: config.nonce,
			ticket_id: ticketId
		};

		if ( action === 'scai_improve_ticket_reply' ) {
			payload.reply_text = replyText;
		}

		setLoading( button, output, true );

		postAjax( config.ajaxUrl, payload )
			.then( function ( response ) {
				if ( response && response.success && response.data ) {
					showResult( output, response.data );
					setLoading( button, output, false );
					return;
				}

				showError( output, getResponseMessage( response ) );
				setLoading( button, output, false );
			}, function () {
				showError( output, getString( 'requestFailed', 'AI request failed. Please try again.' ) );
				setLoading( button, output, false );
			} )
			[ 'catch' ]( function () {
				showError( output, getString( 'requestFailed', 'AI request failed. Please try again.' ) );
				setLoading( button, output, false );
			} );
	}

	function postAjax( ajaxUrl, payload ) {
		if ( window.jQuery && typeof window.jQuery.post === 'function' ) {
			return new Promise( function ( resolve, reject ) {
				window.jQuery.post( ajaxUrl, payload )
					.done( resolve )
					.fail( function ( xhr ) {
						if ( xhr && xhr.responseJSON ) {
							resolve( xhr.responseJSON );
							return;
						}

						reject();
					} );
			} );
		}

		if ( typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function' ) {
			return Promise.reject();
		}

		return fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: new URLSearchParams( payload ).toString()
		} ).then( function ( response ) {
			return response.json()[ 'catch' ]( function () {
				if ( ! response.ok ) {
					throw new Error( 'Request failed' );
				}

				return {};
			} );
		} );
	}

	function setLoading( button, output, isLoading ) {
		if ( button ) {
			button.disabled = isLoading;
		}

		if ( output && isLoading ) {
			output.textContent = getString( 'loading', 'Working...' );
			output.className = 'scai-ticket-ai-output scai-is-loading';
		}
	}

	function showResult( output, data ) {
		var meta = [];
		var content = data && typeof data.content === 'string' ? data.content : '';
		var panel;
		var resultActions;

		if ( ! output ) {
			return;
		}

		latestResultText = content;

		if ( data.provider ) {
			meta.push( 'Provider: ' + data.provider );
		}

		if ( data.model ) {
			meta.push( 'Model: ' + data.model );
		}

		if ( data.tokens ) {
			meta.push( 'Tokens: ' + data.tokens );
		}

		if ( data.duration_ms ) {
			meta.push( 'Duration: ' + data.duration_ms + ' ms' );
		}

		output.className = 'scai-ticket-ai-output scai-has-result';
		output.textContent = ( meta.length ? meta.join( '\n' ) + '\n\n' : '' ) + content;

		panel = getPanelFromElement( output );
		resultActions = panel ? panel.querySelector( '.scai-result-actions' ) : null;

		if ( resultActions && content ) {
			resultActions.style.display = '';
		}

		showPanelMessage( '', '' );
	}

	function getLatestResultText() {
		return String( latestResultText || '' ).trim();
	}

	function copyToClipboard( text ) {
		var textarea;
		var copied;

		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.setAttribute( 'readonly', 'readonly' );
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			textarea.style.top = '0';

			document.body.appendChild( textarea );
			textarea.focus();
			textarea.select();

			try {
				copied = document.execCommand( 'copy' );
			} catch ( error ) {
				copied = false;
			}

			document.body.removeChild( textarea );

			if ( copied ) {
				resolve();
				return;
			}

			reject();
		} );
	}

	function findReplyEditor() {
		return findTinyMceEditor() ||
			findIframeEditor() ||
			findContentEditableEditor() ||
			findTextareaEditor() ||
			findDraftTextareaFallback();
	}

	function findTinyMceEditor() {
		var editor;
		var body;

		if ( ! window.tinymce || ! window.tinymce.activeEditor ) {
			return null;
		}

		editor = window.tinymce.activeEditor;

		if ( typeof editor.isHidden === 'function' && editor.isHidden() ) {
			return null;
		}

		if ( typeof editor.getBody !== 'function' ) {
			return null;
		}

		body = editor.getBody();

		if ( ! body ) {
			return null;
		}

			return {
				type: 'tinymce',
				editor: editor,
				element: body
			};
	}

	function findIframeEditor() {
		var selectors = [
			'.tox-edit-area iframe',
			'iframe[id$="_ifr"]',
			'iframe'
		];
		var index;
		var iframe;
		var body;

		for ( index = 0; index < selectors.length; index++ ) {
			iframe = document.querySelector( selectors[ index ] );
			body = getIframeBody( iframe );

			if ( body && isEditableElement( body ) && isElementVisible( iframe ) ) {
				return {
					type: 'contenteditable',
					element: body
				};
			}
		}

		return null;
	}

	function getIframeBody( iframe ) {
		try {
			return iframe && iframe.contentDocument ? iframe.contentDocument.body : null;
		} catch ( error ) {
			return null;
		}
	}

	function findContentEditableEditor() {
		var selectors = [
			'.wpsc-container [contenteditable="true"]',
			'.wpsc-ticket [contenteditable="true"]',
			'.wpsc-body [contenteditable="true"]',
			'.supportcandy [contenteditable="true"]',
			'.ql-editor',
			'[contenteditable="true"]'
		];

		return findVisibleEditorElement( selectors, 'contenteditable' );
	}

	function findTextareaEditor() {
		var selectors = [
			'.wpsc-container .wp-editor-area',
			'.wpsc-ticket .wp-editor-area',
			'.wpsc-body .wp-editor-area',
			'.supportcandy .wp-editor-area',
			'.wp-editor-area',
			'textarea[name*="reply"]',
			'textarea[name*="description"]'
		];

		return findVisibleEditorElement( selectors, 'textarea' );
	}

	function findDraftTextareaFallback() {
		var draft = document.querySelector( '#scai-draft-reply' );

		if ( ! draft ) {
			return null;
		}

		return {
			type: 'textarea',
			element: draft
		};
	}

	function findVisibleEditorElement( selectors, type ) {
		var index;
		var elements;
		var elementIndex;
		var element;

		for ( index = 0; index < selectors.length; index++ ) {
			elements = document.querySelectorAll( selectors[ index ] );

			for ( elementIndex = 0; elementIndex < elements.length; elementIndex++ ) {
				element = elements[ elementIndex ];

				if ( isPluginDraftEditor( element ) || ! isElementVisible( element ) ) {
					continue;
				}

				return {
					type: type,
					element: element
				};
			}
		}

		return null;
	}

	function isPluginDraftEditor( element ) {
		return !! ( element && element.closest && element.closest( '#' + panelId ) );
	}

	function isPluginElement( element ) {
		return !! ( element && element.closest && element.closest( '#' + panelId ) );
	}

	function isElementVisible( element ) {
		var style;
		var rect;

		if ( ! element ) {
			return false;
		}

		style = window.getComputedStyle ? window.getComputedStyle( element ) : null;

		if ( style && ( style.display === 'none' || style.visibility === 'hidden' ) ) {
			return false;
		}

		rect = element.getBoundingClientRect ? element.getBoundingClientRect() : null;

		return ! rect || rect.width > 0 || rect.height > 0;
	}

	function isEditableElement( element ) {
		return !! (
			element &&
			(
				element.isContentEditable ||
				String( element.getAttribute( 'contenteditable' ) || '' ).toLowerCase() === 'true' ||
				( element.ownerDocument && element.ownerDocument.designMode && element.ownerDocument.designMode.toLowerCase() === 'on' )
			)
		);
	}

	function insertIntoReplyEditor( text ) {
		var editor = findReplyEditor();

		if ( ! editor ) {
			return false;
		}

		return setEditorValue( editor, text );
	}

	function setEditorValue( editor, text ) {
		var element = editor.element;

		if ( ! element ) {
			return false;
		}

		if ( editor.type === 'tinymce' && editor.editor ) {
			editor.editor.setContent( textToSafeHtml( text ) );
			if ( typeof editor.editor.save === 'function' ) {
				editor.editor.save();
			}
			if ( typeof editor.editor.fire === 'function' ) {
				editor.editor.fire( 'change' );
			}
			triggerEditorEvents( element );
			if ( typeof editor.editor.getElement === 'function' && editor.editor.getElement() ) {
				triggerEditorEvents( editor.editor.getElement() );
			}
			return true;
		}

		if ( editor.type === 'textarea' || element.tagName === 'TEXTAREA' || element.tagName === 'INPUT' ) {
			element.value = text;
			triggerEditorEvents( element );
			return true;
		}

		element.textContent = text;
		triggerEditorEvents( element );

		return true;
	}

	function textToSafeHtml( text ) {
		return String( text || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /\r?\n/g, '<br>' );
	}

	function triggerEditorEvents( element ) {
		var inputEvent = document.createEvent( 'HTMLEvents' );
		var changeEvent = document.createEvent( 'HTMLEvents' );

		inputEvent.initEvent( 'input', true, false );
		changeEvent.initEvent( 'change', true, false );

		element.dispatchEvent( inputEvent );
		element.dispatchEvent( changeEvent );
	}

	function showPanelMessage( message, type ) {
		var panel = document.getElementById( panelId );
		var element = panel ? panel.querySelector( '.scai-ticket-ai-message' ) : null;

		if ( ! element ) {
			return;
		}

		element.className = 'scai-ticket-ai-message' + ( type ? ' scai-message-' + type : '' );
		element.textContent = message || '';
	}

	function getPanelFromElement( element ) {
		if ( ! element || ! element.closest ) {
			return document.getElementById( panelId );
		}

		return element.closest( '#' + panelId );
	}

	function showError( output, message ) {
		if ( ! output ) {
			return;
		}

		output.className = 'scai-ticket-ai-output scai-has-error';
		output.textContent = message || getString( 'error', 'Something went wrong.' );
	}

	function getResponseMessage( response ) {
		if ( response && response.data && typeof response.data.message === 'string' ) {
			return response.data.message;
		}

		return getString( 'error', 'Something went wrong.' );
	}

	function refreshTicketAIPanel() {
		var viewState = getTicketViewState();
		var detection = viewState.detection || detectTicketIdWithConfidence();
		var panel = document.getElementById( panelId );
		var output;

		if ( ! viewState.isIndividual ) {
			removePanel();
			return;
		}

		if ( ! panel ) {
			panel = createPanel( detection, viewState );
		} else {
			updatePanelState( panel, detection, viewState );
		}

		if ( ! panel ) {
			return;
		}

		bindEvents( panel );

		if ( ! getConfig().ajaxUrl || ! getConfig().nonce ) {
			output = panel.querySelector( '.scai-ticket-ai-output' );
			showError(
				output,
				getString( 'missingConfig', 'AI actions are not available on this screen.' )
			);
		}
	}

	function removePanel() {
		var panel = document.getElementById( panelId );

		if ( panel && panel.parentNode ) {
			panel.parentNode.removeChild( panel );
		}

		latestResultText = '';
	}

	function scheduleRefresh() {
		if ( refreshTimer ) {
			window.clearTimeout( refreshTimer );
		}

		refreshTimer = window.setTimeout( function () {
			refreshTimer = null;
			refreshTicketAIPanel();
		}, 300 );
	}

	function patchHistoryNavigation() {
		if ( historyPatched || ! window.history ) {
			return;
		}

		patchHistoryMethod( 'pushState' );
		patchHistoryMethod( 'replaceState' );
		historyPatched = true;
	}

	function patchHistoryMethod( method ) {
		var original = window.history[ method ];

		if ( typeof original !== 'function' ) {
			return;
		}

		window.history[ method ] = function () {
			var result = original.apply( window.history, arguments );

			scheduleRefresh();

			return result;
		};
	}

	function startDomObserver() {
		if ( mutationObserver || typeof window.MutationObserver !== 'function' || ! document.body ) {
			return;
		}

		mutationObserver = new window.MutationObserver( function ( mutations ) {
			if ( shouldIgnoreMutations( mutations ) ) {
				return;
			}

			scheduleRefresh();
		} );

		mutationObserver.observe( document.body, {
			childList: true,
			subtree: true
		} );
	}

	function shouldIgnoreMutations( mutations ) {
		var index;
		var mutation;

		if ( ! mutations || ! mutations.length ) {
			return false;
		}

		for ( index = 0; index < mutations.length; index++ ) {
			mutation = mutations[ index ];

			if ( ! isPluginMutation( mutation ) ) {
				return false;
			}
		}

		return true;
	}

	function isPluginMutation( mutation ) {
		return isPluginNodeList( mutation.addedNodes ) &&
			isPluginNodeList( mutation.removedNodes ) &&
			isPluginElement( mutation.target );
	}

	function isPluginNodeList( nodes ) {
		var index;

		if ( ! nodes || ! nodes.length ) {
			return true;
		}

		for ( index = 0; index < nodes.length; index++ ) {
			if ( ! isPluginElement( nodes[ index ] ) ) {
				return false;
			}
		}

		return true;
	}

	function init() {
		if ( initialized ) {
			scheduleRefresh();
			return;
		}

		initialized = true;

		patchHistoryNavigation();
		startDomObserver();
		refreshTicketAIPanel();

		window.addEventListener( 'load', scheduleRefresh );
		window.addEventListener( 'popstate', scheduleRefresh );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
