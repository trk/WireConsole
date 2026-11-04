/**
 * ProcessWireConsole.js
 *
 * Console-specific client-side behavior:
 * - URL `?tab=` and AdminThemeUikit breadcrumb synchronization on HTMX swaps.
 * All Preline UI interactions (CLI copy boxes, typed confirmation modals, dropdowns)
 * are provided globally by WireKit.js.
 */
(function () {
	'use strict';

	var syncTabUrl = function () {
		var workspace = document.getElementById('hxc_wc_workspace');
		if (!workspace) {
			return;
		}
		var activeTab = workspace.getAttribute('data-wc-active-tab');
		var activeTabLabel = workspace.getAttribute('data-wc-active-tab-label') || '';
		if (!activeTab) {
			return;
		}

		// Sync AdminThemeUikit breadcrumb trail with the active Console tab
		var breadcrumbList = document.querySelector('#pw-mastheads ul.uk-breadcrumb, #pw-content-head ul.uk-breadcrumb, ul.uk-breadcrumb');
		if (breadcrumbList) {
			var consoleCrumb = breadcrumbList.querySelector('li[data-wc-crumb-root]');
			if (!consoleCrumb) {
				var items = breadcrumbList.querySelectorAll('li');
				for (var idx = 0; idx < items.length; idx++) {
					var itemText = (items[idx].textContent || '').trim();
					if (itemText === 'Console' || (items[idx].querySelector('a') && items[idx].querySelector('a').getAttribute('href') && items[idx].querySelector('a').getAttribute('href').indexOf('wire-console') !== -1)) {
						consoleCrumb = items[idx];
						consoleCrumb.setAttribute('data-wc-crumb-root', '1');
						break;
					}
				}
			}
			if (consoleCrumb) {
				while (consoleCrumb.nextElementSibling) {
					breadcrumbList.removeChild(consoleCrumb.nextElementSibling);
				}
				if (activeTab !== 'overview' && activeTabLabel) {
					var li = document.createElement('li');
					li.setAttribute('data-wc-crumb-tab', '1');
					var link = document.createElement('a');
					link.href = '?tab=' + encodeURIComponent(activeTab);
					link.textContent = activeTabLabel;
					li.appendChild(link);
					breadcrumbList.appendChild(li);
				}
			}
		}

		if (!window.history || typeof window.history.replaceState !== 'function') {
			return;
		}

		try {
			var url = new URL(window.location.href);
			if (url.searchParams.get('tab') !== activeTab) {
				url.searchParams.set('tab', activeTab);
				window.history.replaceState({}, '', url.toString());
			}
		} catch (e) {
			// Ignore URL sync errors in older environments
		}
	};

	document.addEventListener('htmx:after:swap', syncTabUrl);
})();
