/******/ (() => { // webpackBootstrap
/******/ 	"use strict";

;// ./src/functions/safeTry.ts
function safeTry(fn, $default) {
    try {
        return fn();
    }
    catch {
        return $default;
    }
}

;// ./src/functions/onLoad.ts
/**
 * Executes a function when the DOM is ready (doesn't wait for images/stylesheets)
 * @param fn Function to execute
 */
function onLoad(fn) {
    if (isDOMReady()) {
        return fn();
    }
    // DOMContentLoaded fires when HTML is parsed, much faster than 'load'
    document.addEventListener("DOMContentLoaded", fn);
}
/**
 * Checks if the DOM is ready (interactive or complete)
 */
function isDOMReady() {
    return document.readyState === 'interactive' || document.readyState === 'complete';
}
/**
 * Checks if the document is fully loaded (including images, stylesheets, etc)
 * @deprecated Use isDOMReady() for faster execution
 */
function isDocumentLoaded() {
    return document.readyState === 'complete';
}

;// ./src/functions/watch.ts

function mutationWatch(query, process, root = document) {
    onLoad(() => {
        // Process existing iframes when page loads
        process(root.querySelectorAll(query));
        // Set up observer for dynamically added iframes
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                // Check for added nodes
                if (mutation.addedNodes && mutation.addedNodes.length > 0) {
                    mutation.addedNodes.forEach((node) => {
                        // Check if the added node is an iframe
                        if (node instanceof Element) {
                            if (node.matches(query)) {
                                process([node]);
                            }
                            // Check if the added node contains iframes
                            process(node.querySelectorAll(query));
                        }
                    });
                }
            });
        });
        // Start observing the entire document for changes
        observer.observe(root, {
            childList: true, // Watch for changes to the direct children
            subtree: true // Watch for changes in the entire subtree
        });
        // Function to process iframes and add parent URL parameters
    });
}

;// ./src/functions/storage.ts
function context(name) {
    const context = this;
    return {
        get() {
            return context.get(name);
        },
        set(value) {
            return context.set(name, value);
        }
    };
}
function createStore(storage) {
    return {
        context,
        get(key) {
            return storage.getItem(key) ?? undefined;
        },
        set(key, value) {
            storage.setItem(key, value);
        },
    };
}
function asConst() {
    return (source) => source;
}
const stores = asConst()({
    local: createStore(localStorage),
    session: createStore(sessionStorage),
});

;// ./src/functions/shopifyCookie.ts
/**
 * Shopify Cookie Integration
 * Creates the _sirius_track cookie that Shopify checkout can read
 * This enables cross-domain tracking from landing page -> checkout
 */

/**
 * Creates Shopify-compatible cookie for checkout tracking
 * Cookie format: utm_source=value|utm_medium=value|utm_campaign=value
 */
function createShopifyCookie(config) {
    const { token, domain, utmParam = 'utm_source' } = config;
    // Get leadId from localStorage
    const storageKey = `XTRACKY_LEAD_ID_${token}`;
    const store = stores.local.context(storageKey);
    const leadId = store.get();
    if (!leadId) {
        console.log('[Shopify Cookie] No leadId found in localStorage');
        return;
    }
    // Parse URL parameters
    const url = new URL(window.location.href);
    const utmParams = {
        src: url.searchParams.get('src') || '',
        utm_source: leadId, // Use leadId from storage
        utm_medium: url.searchParams.get('utm_medium') || '',
        utm_campaign: url.searchParams.get('utm_campaign') || '',
        utm_term: url.searchParams.get('utm_term') || '',
        utm_content: url.searchParams.get('utm_content') || '',
    };
    // Build cookie value
    const cookieValue = Object.keys(utmParams)
        .filter((key) => utmParams[key])
        .map((key) => `${key}=${utmParams[key]}`)
        .join('|');
    if (!cookieValue) {
        console.log('[Shopify Cookie] No UTM params to save');
        return;
    }
    const cookieName = '_sirius_track';
    const expiryDate = new Date();
    expiryDate.setMonth(expiryDate.getMonth() + 12); // 1 year expiry
    // Extract root domain
    const hostname = domain || window.location.hostname;
    const cookieDomain = extractRootDomain(hostname);
    console.log('[Shopify Cookie] Creating cookie', {
        domain: cookieDomain,
        cookieValue,
        expiryDate: expiryDate.toUTCString()
    });
    // Set cookie
    document.cookie = `${cookieName}=${cookieValue};domain=.${cookieDomain};path=/;expires=${expiryDate.toUTCString()};SameSite=Lax`;
}
/**
 * Extracts root domain from hostname
 * Examples:
 * - shop.example.com -> example.com
 * - www.shop.com.br -> shop.com.br
 * - example.com -> example.com
 */
function extractRootDomain(hostname) {
    const parts = hostname.split('.');
    if (parts.length >= 3) {
        const tld = parts.pop();
        let domainName = parts.pop();
        // Handle Brazilian domains (.com.br, .net.br, etc)
        if (tld === 'br') {
            domainName = parts.pop() + '.' + domainName;
        }
        return domainName + '.' + tld;
    }
    else {
        // Handle simple domain.tld
        const tld = parts.pop();
        const domainName = parts.pop();
        return domainName + '.' + tld;
    }
}
/**
 * Auto-initialize Shopify cookie creation on page load
 * This watches for URL changes and recreates the cookie
 */
function initShopifyCookieSync(config) {
    // Create cookie on initial load
    createShopifyCookie(config);
    // Recreate cookie when leadId changes in localStorage
    window.addEventListener('storage', (event) => {
        const storageKey = `XTRACKY_LEAD_ID_${config.token}`;
        if (event.key === storageKey && event.newValue) {
            console.log('[Shopify Cookie] LeadId changed, updating cookie');
            createShopifyCookie(config);
        }
    });
    // Watch for URL changes and update cookie
    // This handles SPA navigation
    const originalPushState = history.pushState;
    const originalReplaceState = history.replaceState;
    history.pushState = function (...args) {
        originalPushState.apply(this, args);
        createShopifyCookie(config);
    };
    history.replaceState = function (...args) {
        originalReplaceState.apply(this, args);
        createShopifyCookie(config);
    };
    window.addEventListener('popstate', () => {
        createShopifyCookie(config);
    });
}

;// ./src/functions/initUTMHandler.ts



 // Import the navigation types


function initUTMHandler(hardCodedConfig) {
    // Capture script reference IMMEDIATELY during synchronous execution
    // document.currentScript is only available during script execution, not in callbacks
    const capturedScript = document.currentScript;
    const config = {
        'token': '',
        'clickIdParams': ['click_id', 'ttclid', 'fbclid', 'gclid'], // Support Kwai, TikTok, Facebook, Google
        'stepId': 'initial',
        'currentUrl': new URL(window.location.href),
        'fingerPrintId': undefined,
        'apiEndpoint': 'https://api.xtracky.com/api/browser/view',
    };
    const UTM_SOURCE_PARAM = 'utm_source';
    const SCK_PARAM = 'sck';
    function getCurrentScript() {
        return capturedScript;
    }
    function getDataToken() {
        return capturedScript?.getAttribute("data-token");
    }
    function getLeadIdStorageKey() {
        return `XTRACKY_LEAD_ID_${config.token}`;
    }
    function initializeFromScript() {
        if (capturedScript) {
            Object.assign(config, {
                token: getDataToken() || '',
                stepId: capturedScript.getAttribute("data-step-id") || 'initial',
                currentUrl: new URL(window.location.href),
            });
        }
    }
    function getCookie(name) {
        const nameEQ = name + "=";
        const ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ')
                c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0)
                return c.substring(nameEQ.length, c.length);
        }
        return null;
    }
    function getUrlParameters() {
        // Returns the URLSearchParams converted to an object
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        // If this is a Facebook click (has fbclid), add _fbp cookie if it exists
        if (params['fbclid']) {
            const fbp = getCookie('_fbp');
            if (fbp) {
                params['_fbp'] = fbp;
            }
        }
        return params;
    }
    function detectClickId(urlParams) {
        // Check if any of the supported click ID parameters exist
        for (const clickIdParam of config.clickIdParams) {
            if (urlParams[clickIdParam]) {
                return urlParams[clickIdParam];
            }
        }
        return null;
    }
    function updateUrlWithLeadId(leadId) {
        // Preserve existing query parameters and update/set utm_source and sck
        const newUrl = new URL(window.location.href);
        // Get existing URLSearchParams to preserve all current query parameters
        const searchParams = new URLSearchParams(newUrl.search);
        // Set or update utm_source and sck parameters
        searchParams.set(UTM_SOURCE_PARAM, leadId);
        searchParams.set(SCK_PARAM, leadId);
        newUrl.search = searchParams.toString();
        window.history.replaceState({}, '', newUrl.toString());
        config.currentUrl = newUrl;
    }
    function updateAllLinksWithLeadId(leadId) {
        const links = document.querySelectorAll('a');
        links.forEach(link => {
            if (!link.href || link.href.startsWith('#') || link.href.startsWith('javascript:')) {
                return;
            }
            try {
                const url = new URL(link.href);
                // Update ALL links (internal and external) with utm_source and sck
                url.searchParams.set(UTM_SOURCE_PARAM, leadId);
                url.searchParams.set(SCK_PARAM, leadId);
                link.href = url.href;
            }
            catch (e) {
                // Invalid URL, skip
            }
        });
    }
    async function dispatch(data) {
        if (hasPrevious(data)) {
            return null;
        }
        return run();
        function hasPrevious(data) {
            const PREVIOUS_STORAGE_KEY = 'PREVIOUS_PAGE_VIEW';
            const list = JSON.parse(sessionStorage.getItem(PREVIOUS_STORAGE_KEY) ?? '[]');
            const previous = new Set(list);
            const current = JSON.stringify(data);
            if (previous.has(current))
                return true;
            previous.add(current);
            sessionStorage.setItem(PREVIOUS_STORAGE_KEY, JSON.stringify([...previous.values()]));
            return false;
        }
        async function run() {
            const endpoint = config.apiEndpoint;
            try {
                console.log('VIEW', { data, endpoint });
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(data),
                    signal: AbortSignal.timeout(10000),
                    keepalive: true
                });
                const result = await response.json();
                console.log('VIEW Response', result);
                if (result.success && result.leadId) {
                    return result.leadId;
                }
                return null;
            }
            catch (error) {
                console.warn('Erro ao enviar view:', error);
                return null;
            }
        }
    }
    // In-memory flag to prevent duplicate dispatches (synchronous check)
    let initiateCheckoutSent = false;
    function dispatchInitiateCheckout() {
        // Synchronous check - blocks immediately
        if (initiateCheckoutSent) {
            console.log('[INITIATE_CHECKOUT] Already sent, skipping');
            return;
        }
        initiateCheckoutSent = true;
        const store = stores.local.context(getLeadIdStorageKey());
        const leadId = store.get();
        if (!leadId) {
            console.warn('[INITIATE_CHECKOUT] No leadId found, skipping');
            initiateCheckoutSent = false; // Reset if no leadId
            return;
        }
        // Replace only the last '/view' in the path (not 'view' in domain like api.xtracky.com)
        const endpoint = config.apiEndpoint.replace(/\/view$/, '/initiate-checkout');
        const payload = JSON.stringify({
            product_id: config.token,
            utm_source: leadId,
            href: window.location.href,
        });
        console.log('[INITIATE_CHECKOUT] Sending', { product_id: config.token, utm_source: leadId });
        // Use sendBeacon for reliable delivery during page navigation
        // sendBeacon is designed to send data even when the page is unloading
        if (navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'text/plain' });
            const sent = navigator.sendBeacon(endpoint, blob);
            console.log('[INITIATE_CHECKOUT] Sent via sendBeacon:', sent);
        }
        else {
            // Fallback for older browsers (very rare, <4% of users)
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true
            }).catch(error => console.warn('[INITIATE_CHECKOUT] Error:', error));
        }
    }
    function initCheckoutListeners() {
        // Helper to add listener to an element (only once)
        function addCheckoutListener(element) {
            if (element.dataset.xtrackyCheckoutListenerAdded)
                return;
            element.dataset.xtrackyCheckoutListenerAdded = 'true';
            element.addEventListener('click', dispatchInitiateCheckout);
        }
        // Find all elements with data-xtracky-checkout attribute
        const checkoutElements = document.querySelectorAll('[data-xtracky-checkout]');
        checkoutElements.forEach(addCheckoutListener);
        // Watch for dynamically added checkout elements
        mutationWatch('[data-xtracky-checkout]', elements => {
            elements.forEach(addCheckoutListener);
        });
    }
    // Store rules globally for path checking at click time
    let advancedTrackingRules = [];
    function getAdvancedTrackingConfig() {
        const script = getCurrentScript();
        const base64Config = script?.getAttribute('data-advanced-tracking');
        console.log('[ADVANCED_TRACKING] Script element:', script);
        console.log('[ADVANCED_TRACKING] data-advanced-tracking:', base64Config);
        if (!base64Config)
            return null;
        try {
            const jsonString = atob(base64Config);
            const rules = JSON.parse(jsonString);
            console.log('[ADVANCED_TRACKING] Config decoded:', rules);
            return rules;
        }
        catch (error) {
            console.warn('[ADVANCED_TRACKING] Failed to decode config:', error);
            return null;
        }
    }
    function doesPathMatch(rulePath, currentPath) {
        // Exact match
        if (rulePath === currentPath)
            return true;
        // Support for trailing slash variations
        if (rulePath === currentPath + '/' || rulePath + '/' === currentPath)
            return true;
        // Support for wildcard paths (e.g., /checkout/*)
        if (rulePath.endsWith('*')) {
            const basePath = rulePath.slice(0, -1);
            return currentPath.startsWith(basePath);
        }
        return false;
    }
    function initAdvancedTracking() {
        const rules = getAdvancedTrackingConfig();
        if (!rules || rules.length === 0)
            return;
        // Store rules for path checking at click time
        advancedTrackingRules = rules;
        console.log('[ADVANCED_TRACKING] Initialized with', rules.length, 'rules');
        // Group rules by selector to avoid duplicate watchers
        const selectorRulesMap = new Map();
        rules.forEach(rule => {
            const existing = selectorRulesMap.get(rule.s) || [];
            existing.push(rule);
            selectorRulesMap.set(rule.s, existing);
        });
        // Set up watchers for ALL selectors (SPA-friendly)
        // mutationWatch already handles initial scan + dynamic elements
        selectorRulesMap.forEach((rulesForSelector, selector) => {
            try {
                mutationWatch(selector, elements => {
                    elements.forEach(el => addAdvancedListener(el, rulesForSelector));
                });
                console.log(`[ADVANCED_TRACKING] Watching selector: ${selector} (${rulesForSelector.length} rules)`);
            }
            catch (error) {
                console.warn(`[ADVANCED_TRACKING] Invalid selector: ${selector}`, error);
            }
        });
    }
    function addAdvancedListener(element, rules) {
        // Use a unique key based on all event types for this element
        const eventTypes = [...new Set(rules.map(r => r.e))].sort().join('_');
        const listenerKey = `xtrackyAdvanced_${eventTypes}`;
        if (element.dataset[listenerKey])
            return;
        element.dataset[listenerKey] = 'true';
        element.addEventListener('click', () => {
            const currentPath = window.location.pathname;
            // Find rules that match the CURRENT path (checked at click time, not init time)
            const matchingRules = rules.filter(rule => doesPathMatch(rule.p, currentPath));
            if (matchingRules.length === 0) {
                console.log(`[ADVANCED_TRACKING] Click ignored - path "${currentPath}" doesn't match any rules`);
                return;
            }
            // Dispatch events for all matching rules
            matchingRules.forEach(rule => {
                console.log(`[ADVANCED_TRACKING] Click on "${rule.s}" at "${currentPath}" - Event: ${rule.e}`);
                switch (rule.e) {
                    case 'IC': // InitiateCheckout
                        dispatchInitiateCheckout();
                        break;
                    // Future event types:
                    // case 'AC': dispatchAddToCart(); break;
                    // case 'VC': dispatchViewContent(); break;
                    // case 'PU': dispatchPurchase(); break;
                    default:
                        console.warn(`[ADVANCED_TRACKING] Unknown event type: ${rule.e}`);
                }
            });
        });
    }
    async function handleUtmParameters() {
        const store = stores.local.context(getLeadIdStorageKey());
        const urlParams = getUrlParameters();
        // Check if we have a NEW click ID from any platform (PRIORITY #1)
        const clickId = detectClickId(urlParams);
        console.log({ urlParams, clickId, detectedPlatform: clickId ? 'yes' : 'no' });
        // Check if we already have a stored leadId
        const storedLeadId = store.get();
        // If we have a NEW click ID, process it ONLY if we don't have a stored leadId
        if (clickId && !storedLeadId) {
            // Convert URL params to URLSearchParams string format
            const urlParamsString = new URLSearchParams(urlParams).toString();
            // Build the dispatch data with ALL URL params as URLSearchParams string
            const dispatchData = {
                step_id: config.stepId,
                href: config.currentUrl.href,
                product_id: config.token,
                finger_print_id: config.fingerPrintId ?? await initFingerPrint.promise.promise,
                url_params: urlParamsString, // Send ALL URL parameters as string
            };
            // Send to backend and get leadId
            const leadId = await dispatch(dispatchData);
            if (leadId) {
                console.log('Received NEW leadId from backend', leadId);
                // Save to localStorage (overwrite previous)
                store.set(leadId);
                // Update URL to only have utm_source=leadId
                updateUrlWithLeadId(leadId);
                // Update all links on the page
                updateAllLinksWithLeadId(leadId);
                // Create Shopify cookie for cross-domain tracking
                createShopifyCookie({ token: config.token });
            }
            return;
        }
        // If we have click ID but already have stored leadId, just use the stored one
        if (clickId && storedLeadId) {
            console.log('Click ID detected but using existing leadId from localStorage', storedLeadId);
            updateUrlWithLeadId(storedLeadId);
            updateAllLinksWithLeadId(storedLeadId);
            createShopifyCookie({ token: config.token });
            return;
        }
        // No new click ID, check if we have stored leadId or utm_source in URL
        const utmSourceInUrl = urlParams[UTM_SOURCE_PARAM];
        // If we have utm_source in URL and it matches stored, just propagate it
        if (utmSourceInUrl && storedLeadId && utmSourceInUrl === storedLeadId) {
            console.log('Using existing leadId from URL', utmSourceInUrl);
            updateAllLinksWithLeadId(storedLeadId);
            createShopifyCookie({ token: config.token });
            return;
        }
        // If we have stored leadId but no utm_source in URL, restore it
        if (storedLeadId && !utmSourceInUrl) {
            console.log('Restoring leadId from localStorage', storedLeadId);
            updateUrlWithLeadId(storedLeadId);
            updateAllLinksWithLeadId(storedLeadId);
            createShopifyCookie({ token: config.token });
            return;
        }
        // No click ID, no stored leadId, no utm_source - nothing to do
        console.log('No tracking data available');
    }
    async function dynamicImport(name) {
        return new Function(`return import("${name}")`)();
    }
    function withResolvers() {
        const config = {};
        config.promise = new Promise((resolve, reject) => {
            Object.assign(config, { resolve, reject });
        });
        return config;
    }
    initFingerPrint.promise = withResolvers();
    async function initFingerPrint() {
        try {
            config.fingerPrintId = await getFingerPrintId();
            initFingerPrint.promise.resolve(config.fingerPrintId);
        }
        catch (error) {
            console.warn('[XTRACKY] FingerprintJS initialization failed (CORS or network error). Script continues to work normally without fingerprinting.', error);
            // Resolve with undefined to prevent blocking the algorithm
            initFingerPrint.promise.resolve(undefined);
        }
    }
    async function getFingerPrintId() {
        const FingerprintJS = await dynamicImport('https://cdn.skypack.dev/@fingerprintjs/fingerprintjs@4.0.1').then(res => res.default);
        const fingerPrint = await FingerprintJS.load().then((res) => res.get());
        const id = fingerPrint.visitorId;
        return id;
    }
    onMount();
    async function onMount() {
        initializeFromScript();
        initFingerPrint();
        onLoad(handleUtmParameters);
        onLoad(initCheckoutListeners);
        onLoad(initAdvancedTracking);
        onLoad(maybeLoadConfigMode);
        initWatch();
    }
    // Carrega o script de configuração visual apenas quando necessário
    // Isso evita carregar ~17KB de código que só é usado durante setup
    function maybeLoadConfigMode() {
        const url = new URL(window.location.href);
        const configToken = url.searchParams.get('xtracky_config');
        if (!configToken)
            return; // 99% dos casos - não carrega nada
        console.log('[UTM Handler] Modo configuração detectado, carregando tracking-config...');
        const script = document.createElement('script');
        script.src = 'https://cdn.xtracky.com/scripts/tracking-config.js';
        script.async = true;
        script.onload = () => console.log('[UTM Handler] tracking-config.js carregado com sucesso');
        script.onerror = () => console.warn('[UTM Handler] Falha ao carregar tracking-config.js');
        document.head.appendChild(script);
    }
    function initWatch() {
        if (hardCodedConfig.shouldEnableInterception)
            initNavigationInterception();
        // Watch for iframes and pass through utm_source and sck
        mutationWatch('iframe', iframes => iframes.forEach(iframe => {
            if (iframe.src) {
                const store = stores.local.context(getLeadIdStorageKey());
                const leadId = store.get();
                if (leadId) {
                    const url = new URL(iframe.src);
                    url.searchParams.set(UTM_SOURCE_PARAM, leadId);
                    url.searchParams.set(SCK_PARAM, leadId);
                    iframe.src = url.href;
                }
            }
        }));
        // Watch for new links added dynamically
        mutationWatch('a', () => {
            const store = stores.local.context(getLeadIdStorageKey());
            const leadId = store.get();
            if (leadId) {
                updateAllLinksWithLeadId(leadId);
            }
        });
    }
    function isIframe() {
        try {
            return window.self !== window.top;
        }
        catch (e) {
            return true;
        }
    }
    function initNavigationInterception() {
        interceptWindowOpen();
        startRun();
        function interceptWindowOpen() {
            if (!isIframe())
                return;
            const currentWindow = window;
            const previousOpen = currentWindow.open;
            window.open = function open(input, target, ...others) {
                if (isIframe() && target === '_top') {
                    const url = safeFactoryURL(input);
                    if (url)
                        return previousOpen.call(this, getURL(url.href), target, ...others);
                }
                return previousOpen.apply(this, arguments);
            };
        }
        function isSafari() {
            return /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        }
        function startRun() {
            // Safari's polyfill doesn't support window.location.href redirects
            // This breaks Shopify themes and Yampi checkout that use location.href
            if (isSafari()) {
                console.log('[Navigation Interception] Safari detected - skipping (polyfill incompatible)');
                return;
            }
            if (!window.navigation) {
                console.log('[Navigation Interception] No native Navigation API - skipping');
                return;
            }
            console.log('[Navigation Interception] Native support detected - enabled');
            run();
            function run() {
                let lastURL;
                window.navigation?.addEventListener("navigate", (event) => {
                    const navigation = window.navigation;
                    if (!event?.destination?.url)
                        return;
                    safeTry(() => {
                        event.destination.url = event?.destination?.url?.href ?? event?.destination?.url;
                    });
                    if (!shouldIntercept(event))
                        return;
                    // For non-form navigations, proceed with URL modification
                    event.preventDefault();
                    redirect(event, getURL(event.destination.url));
                    function redirect(event, url) {
                        lastURL = url;
                        if (event.formData && (event.sourceElement instanceof HTMLFormElement)) {
                            const actionUrl = new URL(event.sourceElement.action);
                            const currentLeadId = stores.local.context(getLeadIdStorageKey()).get();
                            if (currentLeadId) {
                                actionUrl.searchParams.set(UTM_SOURCE_PARAM, currentLeadId);
                                actionUrl.searchParams.set(SCK_PARAM, currentLeadId);
                                event.sourceElement.action = actionUrl.href;
                                event.sourceElement?.submit();
                            }
                            return;
                        }
                        const shouldRefresh = !event.destination.sameDocument;
                        if (shouldRefresh)
                            return navigation.navigate(url, { history: event.navigationType === 'push' ? 'push' : event.navigationType === 'replace' ? 'replace' : 'auto' });
                        history.pushState({}, '', url);
                    }
                    function shouldIntercept(event) {
                        return lastURL !== event.destination.url;
                    }
                });
            }
        }
        function getRelevantQuerySearch() {
            const searchParams = new URLSearchParams(window.location.search);
            return omitNullish(Object.fromEntries([UTM_SOURCE_PARAM, SCK_PARAM].map(id => [id, getQuerySearchParam(id, searchParams)])));
        }
        function getQuerySearchParam(id, searchParams = new URLSearchParams(window.location.search)) {
            return searchParams.get(id) ?? undefined;
        }
        function omitNullish(source) {
            const content = {};
            for (const name in source)
                if (source[name] != null)
                    content[name] = source[name];
            return content;
        }
        function getURL(to) {
            return mergeURLSearchs({ url: to, search: [new URLSearchParams(getRelevantQuerySearch()), to] });
            function mergeURLSearchs({ url, search }) {
                const main = new URL(url);
                const searchConfig = omitNullish(Object.assign({}, ...search.map(getSearchParams).map(Object.fromEntries)));
                main.search = new URLSearchParams(searchConfig).toString();
                return main.href;
                function getSearchParams(url) {
                    if (url instanceof URLSearchParams)
                        return url;
                    if (url instanceof URL)
                        return url.searchParams;
                    return safeFactoryURL(url)?.searchParams ?? new URLSearchParams(url);
                }
            }
        }
        function safeFactoryURL(url) {
            try {
                if (url instanceof URL)
                    return url;
                return new URL(url);
            }
            catch {
                return;
            }
        }
        function polyfill() {
            if (!window.navigation) {
                // Dynamically load the polyfill only if needed
                const polyfillScript = document.createElement('script');
                polyfillScript.type = 'module';
                polyfillScript.textContent = `
                    // Import the polyfill from Skypack
                    import * as navigationPolyfill from 'https://cdn.skypack.dev/navigation-api-polyfill';
                    window.dispatchEvent(new Event('navigationReady'));
                `;
                document.head.appendChild(polyfillScript);
            }
            else {
                // Navigation API is natively supported, dispatch ready event immediately
                window.dispatchEvent(new Event('navigationReady'));
            }
        }
    }
}

;// ./src/export/utm-handler.ts

// Initialize the UTM handler for multi-platform tracking
// Supports: Kwai (click_id), TikTok (ttclid), Facebook (fbclid), Google (gclid)
initUTMHandler({
    shouldEnableInterception: false,
});

/******/ })()
;
