/*
|--------------------------------------------------------------------------
| HOTNET CONNECTION HEARTBEAT
|--------------------------------------------------------------------------
| File: hotspot/heartbeat.js
|
| Responsibilities:
| 1. Check connection status periodically.
| 2. Communicate with device_heartbeat.php.
| 3. Display connection status.
| 4. Detect expired sessions.
| 5. Handle internet/network interruptions.
|--------------------------------------------------------------------------
*/

(function () {
    "use strict";

    const CONFIG = {
        heartbeatUrl: "device_heartbeat.php",
        interval: 60000,
        timeout: 15000,
        maxFailures: 3
    };

    let heartbeatTimer = null;
    let requestController = null;
    let running = false;
    let checking = false;
    let failedAttempts = 0;

    /*
    |--------------------------------------------------------------------------
    | GET CSRF TOKEN
    |--------------------------------------------------------------------------
    */

    function getCsrfToken() {
        const meta = document.querySelector(
            'meta[name="heartbeat-csrf"]'
        );

        return meta ? meta.getAttribute("content") : "";
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CONNECTION DISPLAY
    |--------------------------------------------------------------------------
    */

    function updateStatus(status, message) {
        const statusElement = document.getElementById(
            "hotnet-connection-status"
        );

        const messageElement = document.getElementById(
            "hotnet-connection-message"
        );

        if (statusElement) {
            statusElement.textContent = status;

            statusElement.classList.remove(
                "text-success",
                "text-danger",
                "text-warning"
            );

            if (status === "Connected") {
                statusElement.classList.add("text-success");
            } else if (status === "Disconnected") {
                statusElement.classList.add("text-danger");
            } else {
                statusElement.classList.add("text-warning");
            }
        }

        if (messageElement) {
            messageElement.textContent = message;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE EXPIRED SESSION
    |--------------------------------------------------------------------------
    */

    function handleSessionExpired(message) {
        stop();

        updateStatus(
            "Disconnected",
            message || "Your session has expired. Please reconnect."
        );

        window.dispatchEvent(
            new CustomEvent("hotnet:session-expired", {
                detail: {
                    message: message || "Session expired"
                }
            })
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SEND HEARTBEAT REQUEST
    |--------------------------------------------------------------------------
    */

    async function check() {

        if (checking || !running) {
            return;
        }

        if (!navigator.onLine) {
            updateStatus(
                "Checking",
                "Your browser is offline. Waiting for network connection."
            );

            return;
        }

        const csrfToken = getCsrfToken();

        if (!csrfToken) {
            updateStatus(
                "Disconnected",
                "Security token is missing. Refresh the page."
            );

            stop();
            return;
        }

        checking = true;

        requestController = new AbortController();

        const timeoutId = setTimeout(function () {
            requestController.abort();
        }, CONFIG.timeout);

        try {

            const formData = new FormData();

            formData.append("csrf_token", csrfToken);

            const response = await fetch(
                CONFIG.heartbeatUrl,
                {
                    method: "POST",
                    body: formData,
                    credentials: "same-origin",
                    cache: "no-store",
                    signal: requestController.signal,
                    headers: {
                        "X-Requested-With": "XMLHttpRequest"
                    }
                }
            );

            /*
            |--------------------------------------------------------------------------
            | CHECK SESSION STATUS
            |--------------------------------------------------------------------------
            */

            if (response.status === 401 || response.status === 403) {

                handleSessionExpired(
                    "Your session is no longer valid. Please reconnect."
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK SERVER RESPONSE
            |--------------------------------------------------------------------------
            */

            if (!response.ok) {
                throw new Error(
                    "Server returned HTTP " + response.status
                );
            }

            const result = await response.json();

            if (!result || typeof result.success === "undefined") {
                throw new Error("Invalid server response.");
            }

            /*
            |--------------------------------------------------------------------------
            | HANDLE SUCCESS
            |--------------------------------------------------------------------------
            */

            if (result.success === true) {

                failedAttempts = 0;

                updateStatus(
                    "Connected",
                    result.message || "Your HOTNET connection is active."
                );

                window.dispatchEvent(
                    new CustomEvent("hotnet:heartbeat-success", {
                        detail: result.data || {}
                    })
                );

            } else {

                failedAttempts++;

                updateStatus(
                    "Checking",
                    result.message || "Connection verification failed."
                );

                if (failedAttempts >= CONFIG.maxFailures) {

                    stop();

                    updateStatus(
                        "Disconnected",
                        result.message ||
                        "Unable to verify your connection. Please reconnect."
                    );

                    window.dispatchEvent(
                        new CustomEvent("hotnet:connection-error", {
                            detail: {
                                message: result.message || "Connection verification failed."
                            }
                        })
                    );
                }
            }

        } catch (error) {

            failedAttempts++;

            if (error.name === "AbortError") {

                updateStatus(
                    "Checking",
                    "Connection check timed out."
                );

            } else {

                updateStatus(
                    "Checking",
                    "Unable to contact HOTNET server. Retrying..."
                );
            }

            if (failedAttempts >= CONFIG.maxFailures) {

                updateStatus(
                    "Disconnected",
                    "Connection could not be verified. Check your network."
                );

                window.dispatchEvent(
                    new CustomEvent("hotnet:connection-error", {
                        detail: {
                            message: error.message
                        }
                    })
                );
            }

        } finally {

            clearTimeout(timeoutId);

            requestController = null;

            checking = false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | START HEARTBEAT
    |--------------------------------------------------------------------------
    */

    function start() {

        if (running) {
            return;
        }

        running = true;

        failedAttempts = 0;

        updateStatus(
            "Checking",
            "Checking your HOTNET connection..."
        );

        check();

        heartbeatTimer = setInterval(function () {

            if (!document.hidden) {
                check();
            }

        }, CONFIG.interval);
    }

    /*
    |--------------------------------------------------------------------------
    | STOP HEARTBEAT
    |--------------------------------------------------------------------------
    */

    function stop() {

        running = false;

        if (heartbeatTimer !== null) {

            clearInterval(heartbeatTimer);

            heartbeatTimer = null;
        }

        if (requestController !== null) {

            requestController.abort();

            requestController = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BROWSER ONLINE EVENT
    |--------------------------------------------------------------------------
    */

    window.addEventListener("online", function () {

        if (running) {

            updateStatus(
                "Checking",
                "Network restored. Checking your connection..."
            );

            check();
        }

    });

    /*
    |--------------------------------------------------------------------------
    | BROWSER OFFLINE EVENT
    |--------------------------------------------------------------------------
    */

    window.addEventListener("offline", function () {

        updateStatus(
            "Disconnected",
            "Your browser has lost its network connection."
        );

    });

    /*
    |--------------------------------------------------------------------------
    | PAGE VISIBILITY EVENT
    |--------------------------------------------------------------------------
    */

    document.addEventListener("visibilitychange", function () {

        if (!document.hidden && running) {
            check();
        }

    });

    /*
    |--------------------------------------------------------------------------
    | EXPOSE HOTNET HEARTBEAT FUNCTIONS
    |--------------------------------------------------------------------------
    */

    window.HOTNET = window.HOTNET || {};

    window.HOTNET.heartbeat = {
        start: start,
        stop: stop,
        check: check,

        isRunning: function () {
            return running;
        },

        getFailedAttempts: function () {
            return failedAttempts;
        }
    };

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC START
    |--------------------------------------------------------------------------
    */

    if (document.readyState === "loading") {

        document.addEventListener(
            "DOMContentLoaded",
            start,
            { once: true }
        );

    } else {

        start();

    }

})();