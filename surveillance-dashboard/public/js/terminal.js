/**
 * RoadShield Remote SSH Terminal Access JS Controller
 */
(function () {
    'use strict';

    let terminalPollInterval = null;
    let countdownInterval = null;
    let remainingSeconds = 0;

    // Helper: get device ID from the URL path (/surveillance/devices/{deviceId})
    function getDeviceId() {
        const pathParts = window.location.pathname.split('/');
        return pathParts[pathParts.length - 1];
    }

    /**
     * Request a new terminal session from Laravel
     */
    window.requestTerminalSession = function () {
        const deviceId = getDeviceId();
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';

        // Reset UI state
        const panel = document.getElementById('sv-terminal-panel');
        const spinner = document.getElementById('terminal-status-spinner');
        const statusText = document.getElementById('terminal-status-text');
        const connectionDetails = document.getElementById('terminal-connection-details');

        panel.classList.remove('hidden');
        spinner.classList.remove('hidden');
        statusText.innerText = "Requesting remote access terminal session...";
        connectionDetails.classList.add('hidden');

        // Disable Request button during the process
        const reqBtn = document.getElementById('access-terminal-btn');
        if (reqBtn) reqBtn.disabled = true;

        fetch(`/surveillance/devices/${deviceId}/terminal/request`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Authorization': `Bearer ${token}`
            }
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => { throw err; });
            }
            return response.json();
        })
        .then(data => {
            console.log("Terminal request created:", data);
            statusText.innerText = "Command queued. Waiting for remote agent to connect...";
            
            // Start polling status
            startStatusPolling();
        })
        .catch(err => {
            console.error("Failed to request terminal:", err);
            statusText.innerText = `Error: ${err.error || 'Failed to initialize session'}`;
            spinner.classList.add('hidden');
            if (reqBtn) reqBtn.disabled = false;
        });
    };

    /**
     * Terminate / Close terminal session
     */
    window.terminateTerminalSession = function () {
        stopPolling();
        
        const panel = document.getElementById('sv-terminal-panel');
        panel.classList.add('hidden');
        
        const reqBtn = document.getElementById('access-terminal-btn');
        if (reqBtn) reqBtn.disabled = false;
    };

    /**
     * Poll terminal session status
     */
    function startStatusPolling() {
        stopPolling();
        
        const deviceId = getDeviceId();
        const token = document.querySelector('meta[name="surveillance-token"]')?.content || '';

        terminalPollInterval = setInterval(() => {
            fetch(`/surveillance/devices/${deviceId}/terminal/status`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            })
            .then(res => res.json())
            .then(data => {
                handleStatusUpdate(data);
            })
            .catch(err => console.error("Error polling terminal status:", err));
        }, 2000);
    }

    function handleStatusUpdate(data) {
        const spinner = document.getElementById('terminal-status-spinner');
        const statusText = document.getElementById('terminal-status-text');
        const connectionDetails = document.getElementById('terminal-connection-details');
        const connStringInput = document.getElementById('terminal-connection-string');
        const portVal = document.getElementById('terminal-stat-port');

        console.log("Terminal status update:", data);

        if (data.status === 'requested') {
            statusText.innerText = "Pending command pickup by Python agent...";
            spinner.classList.remove('hidden');
            connectionDetails.classList.add('hidden');
        } 
        else if (data.status === 'open') {
            statusText.innerText = "Reverse SSH Tunnel is Active.";
            spinner.classList.add('hidden');
            connectionDetails.classList.remove('hidden');
            
            connStringInput.value = data.connection_string;
            portVal.innerText = data.port;
            
            // Handle timer countdown
            remainingSeconds = data.remaining_seconds;
            startCountdown();
            
            // We can slow down polling once connected
            stopPolling(false); // Stop status polling, keep countdown running
        } 
        else if (data.status === 'closed' || data.status === 'expired') {
            statusText.innerText = `Session is ${data.status.toUpperCase()}.`;
            spinner.classList.add('hidden');
            connectionDetails.classList.add('hidden');
            stopPolling();
            
            const reqBtn = document.getElementById('access-terminal-btn');
            if (reqBtn) reqBtn.disabled = false;
        }
    }

    /**
     * Start expiry countdown
     */
    function startCountdown() {
        if (countdownInterval) clearInterval(countdownInterval);
        
        const timeVal = document.getElementById('terminal-stat-time');
        
        countdownInterval = setInterval(() => {
            if (remainingSeconds <= 0) {
                clearInterval(countdownInterval);
                timeVal.innerText = "Expired";
                terminateTerminalSession();
                return;
            }
            
            remainingSeconds--;
            
            const mins = Math.floor(remainingSeconds / 60);
            const secs = remainingSeconds % 60;
            timeVal.innerText = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }, 1000);
    }

    function stopPolling(stopCountdown = true) {
        if (terminalPollInterval) {
            clearInterval(terminalPollInterval);
            terminalPollInterval = null;
        }
        if (stopCountdown && countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    /**
     * Copy connection string to clipboard
     */
    window.copyConnectionString = function () {
        const copyText = document.getElementById('terminal-connection-string');
        if (!copyText) return;
        
        copyText.select();
        copyText.setSelectionRange(0, 99999); // Mobile compatibility
        
        navigator.clipboard.writeText(copyText.value)
            .then(() => {
                alert("SSH connection command copied to clipboard!");
            })
            .catch(err => {
                console.error("Copy failed:", err);
            });
    };

})();
