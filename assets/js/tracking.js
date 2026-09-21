/**
 * Hubbee Analytics Tracking Script
 *
 * Tracks page views, web vitals, and traffic sources.
 * Sends data to WordPress REST endpoint which proxies to SaaS.
 */
(function() {
  'use strict';

  // Config is injected by PHP via wp_localize_script
  var config = window.hubbeeTracking || {};

  if (!config.endpoint || !config.siteId) {
    return;
  }

  // Generate anonymous session hash (no PII)
  function getSessionHash() {
    var stored = sessionStorage.getItem('bz_session');
    if (stored) {
      return stored;
    }

    // Generate random hash
    var hash = 'bz_' + Math.random().toString(36).substring(2, 15) +
               Math.random().toString(36).substring(2, 15);
    sessionStorage.setItem('bz_session', hash);
    return hash;
  }

  // Classify traffic source
  function getTrafficSource() {
    var referrer = document.referrer;

    if (!referrer) {
      return { type: 'direct', domain: null };
    }

    try {
      var refUrl = new URL(referrer);
      var refDomain = refUrl.hostname;

      // Check if same site (internal navigation)
      if (refDomain === window.location.hostname) {
        return { type: 'direct', domain: null };
      }

      // Search engines
      var searchEngines = [
        'google.com', 'google.de', 'bing.com', 'yahoo.com',
        'duckduckgo.com', 'ecosia.org', 'baidu.com', 'yandex.ru'
      ];

      for (var i = 0; i < searchEngines.length; i++) {
        if (refDomain.indexOf(searchEngines[i]) !== -1) {
          return { type: 'organic', domain: refDomain };
        }
      }

      // Social media
      var socialNetworks = [
        'facebook.com', 'twitter.com', 'x.com', 'linkedin.com',
        'instagram.com', 'pinterest.com', 'tiktok.com', 'youtube.com'
      ];

      for (var j = 0; j < socialNetworks.length; j++) {
        if (refDomain.indexOf(socialNetworks[j]) !== -1) {
          return { type: 'social', domain: refDomain };
        }
      }

      // Default: referral
      return { type: 'referral', domain: refDomain };

    } catch (e) {
      return { type: 'direct', domain: null };
    }
  }

  // Prepare page view data. Bounce state is derived server-side from the
  // session_hash (a session with a single pageview is a bounce).
  var source = getTrafficSource();
  var pageViewData = {
    path: window.location.pathname,
    session_hash: getSessionHash(),
    referrer_domain: source.domain,
    source_type: source.type,
    user_agent: navigator.userAgent
  };

  // Collect web vitals
  var webVitalsData = {
    path: window.location.pathname,
    lcp: null,
    cls: null,
    inp: null
  };

  var vitalsCollected = {
    lcp: false,
    cls: false,
    inp: false
  };

  // Track the first time the page became hidden, so Web Vitals (especially LCP)
  // measured while the tab was in the background can be discarded. Background
  // tabs defer rendering, so LCP fires very late (10s-40s) and poisons the
  // aggregates. Mirrors the google/web-vitals visibility guard.
  var firstHiddenTime = (document.visibilityState === 'hidden') ? 0 : Infinity;
  document.addEventListener('visibilitychange', function onFirstHide() {
    if (document.visibilityState === 'hidden') {
      firstHiddenTime = Math.min(firstHiddenTime, performance.now());
    }
  }, true);

  // Defensive upper bound: a real LCP is never minutes. Beyond this it is a
  // measurement artifact (throttled/background tab) and is dropped.
  var LCP_SANITY_MAX_MS = 120000;

  // A pageview must be reported exactly once. Both exit signals below
  // (visibilitychange→hidden and pagehide) can fire for the same navigation —
  // without this guard every tab switch would re-send the same pageview and
  // inflate all counters.
  var analyticsSent = false;

  // Send data to endpoint
  function sendAnalytics() {
    if (analyticsSent) {
      return;
    }
    analyticsSent = true;

    var payload = {
      page_views: [pageViewData],
      web_vitals: []
    };

    // Include web vitals if any were collected
    if (vitalsCollected.lcp || vitalsCollected.cls || vitalsCollected.inp) {
      var vitals = { path: webVitalsData.path };
      if (vitalsCollected.lcp && webVitalsData.lcp !== null) {
        vitals.lcp = webVitalsData.lcp;
      }
      if (vitalsCollected.cls && webVitalsData.cls !== null) {
        vitals.cls = webVitalsData.cls;
      }
      if (vitalsCollected.inp && webVitalsData.inp !== null) {
        vitals.inp = webVitalsData.inp;
      }
      payload.web_vitals.push(vitals);
    }

    // Use sendBeacon for reliability on page unload
    var url = config.endpoint;
    var data = JSON.stringify(payload);

    if (navigator.sendBeacon) {
      navigator.sendBeacon(url, data);
    } else {
      // Fallback to fetch
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: data,
        keepalive: true
      }).catch(function() {
        // Silently fail
      });
    }
  }

  // Web Vitals collection using PerformanceObserver
  function observeWebVitals() {
    // Guard against double registration (SPA-safe)
    if (window.__hubbeeObserversInit) return;
    window.__hubbeeObserversInit = true;

    // LCP - Largest Contentful Paint
    if ('PerformanceObserver' in window) {
      try {
        var lcpObserver = new PerformanceObserver(function(entryList) {
          // Page loaded in a background tab → LCP timing is meaningless.
          if (firstHiddenTime === 0) return;
          var entries = entryList.getEntries();
          var lastEntry = entries[entries.length - 1];
          if (!lastEntry) return;
          // Discard if the tab was hidden before this LCP, or the value is absurd.
          if (lastEntry.startTime >= firstHiddenTime) return;
          if (lastEntry.startTime > LCP_SANITY_MAX_MS) return;
          webVitalsData.lcp = Math.round(lastEntry.startTime);
          vitalsCollected.lcp = true;
        });
        lcpObserver.observe({ type: 'largest-contentful-paint', buffered: true });
      } catch (e) {
        // LCP not supported
      }

      // CLS - Cumulative Layout Shift
      try {
        var clsValue = 0;
        var clsObserver = new PerformanceObserver(function(entryList) {
          var entries = entryList.getEntries();
          for (var i = 0; i < entries.length; i++) {
            if (!entries[i].hadRecentInput) {
              clsValue += entries[i].value;
            }
          }
          webVitalsData.cls = Math.round(clsValue * 1000) / 1000;
          vitalsCollected.cls = true;
        });
        clsObserver.observe({ type: 'layout-shift', buffered: true });
      } catch (e) {
        // CLS not supported
      }

      // INP - Interaction to Next Paint
      try {
        var inpValue = 0;
        var inpObserver = new PerformanceObserver(function(entryList) {
          var entries = entryList.getEntries();
          for (var i = 0; i < entries.length; i++) {
            var duration = entries[i].duration;
            if (duration > inpValue) {
              inpValue = duration;
              webVitalsData.inp = Math.round(duration);
              vitalsCollected.inp = true;
            }
          }
        });
        inpObserver.observe({ type: 'event', buffered: true, durationThreshold: 16 });
      } catch (e) {
        // INP not supported
      }
    }
  }

  // Initialize
  observeWebVitals();

  // Primary exit signal: page becomes hidden (tab switch, navigation, close).
  // This is the most reliable signal on mobile, where unload events may never fire.
  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
      sendAnalytics();
    }
  });

  // Fallback for browsers that unload without a visibilitychange (pagehide is
  // more reliable than beforeunload and fires on bfcache navigations too).
  window.addEventListener('pagehide', function() {
    sendAnalytics();
  });

})();
