/**
 * الحلقات الأسرية - Sync Module
 * BroadcastChannel for same-device sync + WebRTC DataChannel for peer-to-peer
 */

// ==================== BroadcastChannel (Same Device) ====================
let syncChannel = null;

function initSync() {
    // BroadcastChannel for tabs on same device
    try {
        syncChannel = new BroadcastChannel('family_halqat_sync');
        syncChannel.onmessage = (event) => {
            handleSyncMessage(event.data);
        };
    } catch (e) {
        console.log('BroadcastChannel not supported, using localStorage fallback');
        // Fallback: listen to storage events
        window.addEventListener('storage', (e) => {
            if (e.key === 'family_halqat_sync_signal') {
                try {
                    const data = JSON.parse(e.newValue);
                    handleSyncMessage(data);
                } catch (err) { }
            }
        });
    }

    // Initialize WebRTC peer connection
    initWebRTC();
}

function broadcastSync(type, payload = {}) {
    const message = {
        type,
        payload,
        timestamp: Date.now(),
        sender: getSenderId()
    };

    // BroadcastChannel
    if (syncChannel) {
        try {
            syncChannel.postMessage(message);
        } catch (e) { }
    }

    // localStorage fallback
    try {
        localStorage.setItem('family_halqat_sync_signal', JSON.stringify(message));
    } catch (e) { }

    // WebRTC
    sendViaDataChannel(message);
}

function handleSyncMessage(data) {
    if (!data || data.sender === getSenderId()) return;

    console.log('Sync received:', data.type);

    // Dispatch custom event for page-specific handlers
    window.dispatchEvent(new CustomEvent('sync-update', { detail: data }));

    // Auto-refresh data based on message type
    switch (data.type) {
        case 'assignment_added':
        case 'assignment_completed':
        case 'assignment_reviewed':
        case 'student_added':
            // Trigger page reload functions if they exist
            if (typeof loadDashboard === 'function') loadDashboard();
            if (typeof loadData === 'function') loadData();
            break;
    }
}

function getSenderId() {
    let id = sessionStorage.getItem('family_halqat_sender_id');
    if (!id) {
        id = 'device_' + Math.random().toString(36).substr(2, 9);
        sessionStorage.setItem('family_halqat_sender_id', id);
    }
    return id;
}

// ==================== WebRTC Peer-to-Peer ====================
const ICE_SERVERS = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' }
    ]
};

let peerConnection = null;
let dataChannel = null;
let isInitiator = false;

/**
 * Simple WebRTC signaling via localStorage polling
 * This works for devices on the same network that share the same server
 */
function initWebRTC() {
    // Use API for signaling exchange
    const familyId = localStorage.getItem('family_halqat_family_id');
    if (!familyId) {
        // Generate a family ID for first time
        const newId = 'family_' + Math.random().toString(36).substr(2, 8);
        localStorage.setItem('family_halqat_family_id', newId);
    }

    // Poll for signaling messages
    startSignalingPoll();
}

function createPeerConnection() {
    if (peerConnection) {
        peerConnection.close();
    }

    peerConnection = new RTCPeerConnection(ICE_SERVERS);

    peerConnection.onicecandidate = (event) => {
        if (event.candidate) {
            sendSignal({
                type: 'ice-candidate',
                candidate: event.candidate
            });
        }
    };

    peerConnection.ondatachannel = (event) => {
        dataChannel = event.channel;
        setupDataChannel();
    };

    peerConnection.onconnectionstatechange = () => {
        const state = peerConnection.connectionState;
        console.log('WebRTC state:', state);
    };

    return peerConnection;
}

function setupDataChannel() {
    if (!dataChannel) return;

    dataChannel.onopen = () => {
        console.log('DataChannel open');
        showToast('تم الاتصال بجهاز آخر في الأسرة 🔗', 'success');
    };

    dataChannel.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            handleSyncMessage(data);
        } catch (e) {
            console.error('DataChannel message parse error:', e);
        }
    };

    dataChannel.onclose = () => {
        console.log('DataChannel closed');
    };
}

async function initiateConnection() {
    try {
        createPeerConnection();
        isInitiator = true;

        dataChannel = peerConnection.createDataChannel('family_halqat_sync');
        setupDataChannel();

        const offer = await peerConnection.createOffer();
        await peerConnection.setLocalDescription(offer);

        sendSignal({
            type: 'offer',
            sdp: offer
        });
    } catch (e) {
        console.error('WebRTC initiate error:', e);
    }
}

async function handleOffer(offer) {
    try {
        createPeerConnection();

        await peerConnection.setRemoteDescription(new RTCSessionDescription(offer.sdp));
        const answer = await peerConnection.createAnswer();
        await peerConnection.setLocalDescription(answer);

        sendSignal({
            type: 'answer',
            sdp: answer
        });
    } catch (e) {
        console.error('WebRTC handle offer error:', e);
    }
}

async function handleAnswer(answer) {
    try {
        if (peerConnection) {
            await peerConnection.setRemoteDescription(new RTCSessionDescription(answer.sdp));
        }
    } catch (e) {
        console.error('WebRTC handle answer error:', e);
    }
}

async function handleIceCandidate(message) {
    try {
        if (peerConnection && message.candidate) {
            await peerConnection.addIceCandidate(new RTCIceCandidate(message.candidate));
        }
    } catch (e) {
        console.error('WebRTC ICE error:', e);
    }
}

function sendViaDataChannel(message) {
    if (dataChannel && dataChannel.readyState === 'open') {
        try {
            dataChannel.send(JSON.stringify(message));
        } catch (e) {
            console.error('DataChannel send error:', e);
        }
    }
}

// ==================== Signaling via Server ====================
// Simple polling-based signaling using the server as relay

let signalingInterval = null;

function startSignalingPoll() {
    // Poll every 5 seconds for new signals
    signalingInterval = setInterval(async () => {
        try {
            const signals = getStoredSignals();
            for (const signal of signals) {
                if (signal.sender === getSenderId()) continue;

                switch (signal.type) {
                    case 'offer':
                        await handleOffer(signal);
                        break;
                    case 'answer':
                        await handleAnswer(signal);
                        break;
                    case 'ice-candidate':
                        await handleIceCandidate(signal);
                        break;
                    case 'request-connect':
                        // Someone wants to connect
                        if (!isInitiator && (!peerConnection || peerConnection.connectionState === 'closed')) {
                            await initiateConnection();
                        }
                        break;
                }
            }
        } catch (e) { }
    }, 5000);

    // Announce presence
    sendSignal({ type: 'request-connect' });
}

function sendSignal(data) {
    const signal = {
        ...data,
        sender: getSenderId(),
        timestamp: Date.now()
    };

    try {
        let signals = JSON.parse(localStorage.getItem('family_halqat_signals') || '[]');
        // Keep only recent signals (last 30 seconds)
        signals = signals.filter(s => Date.now() - s.timestamp < 30000);
        signals.push(signal);
        localStorage.setItem('family_halqat_signals', JSON.stringify(signals));
    } catch (e) { }
}

function getStoredSignals() {
    try {
        let signals = JSON.parse(localStorage.getItem('family_halqat_signals') || '[]');
        // Only get signals from last 30 seconds that we haven't processed
        const lastProcessed = parseInt(sessionStorage.getItem('family_halqat_last_signal') || '0');
        const recent = signals.filter(s => s.timestamp > lastProcessed && s.sender !== getSenderId());

        if (recent.length > 0) {
            sessionStorage.setItem('family_halqat_last_signal', String(Date.now()));
        }

        return recent;
    } catch (e) {
        return [];
    }
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (signalingInterval) clearInterval(signalingInterval);
    if (dataChannel) dataChannel.close();
    if (peerConnection) peerConnection.close();
    if (syncChannel) syncChannel.close();
});
