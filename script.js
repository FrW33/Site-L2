const launchDate = new Date("2026-08-01T18:59:00").getTime();

function updateTimer() {
    const now = new Date().getTime();
    const distance = launchDate - now;
    const d = Math.floor(distance / (1000 * 60 * 60 * 24));
    const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
    const s = Math.floor((distance % (1000 * 60)) / 1000);
    if (distance < 0) return;
    
    // Check if elements exist before updating to prevent errors
    if(document.getElementById("days")) {
        document.getElementById("days").innerText = d.toString().padStart(2, '0');
        document.getElementById("hours").innerText = h.toString().padStart(2, '0');
        document.getElementById("minutes").innerText = m.toString().padStart(2, '0');
        document.getElementById("seconds").innerText = s.toString().padStart(2, '0');
    }
}

setInterval(updateTimer, 1000);
updateTimer();

function simulateVisits() {
    const countEl = document.getElementById('visit-count');
    if(countEl) {
        let count = parseInt(countEl.innerText.replace(',', ''));
        if (Math.random() > 0.7) {
            count += 1;
            countEl.innerText = count.toLocaleString();
        }
    }
}

setInterval(simulateVisits, 5000);

// NUEVO: SISTEMA DE ESTADO DEL SERVIDOR
async function fetchServerStatus() {
    try {
        // RUTA CORREGIDA: Como se ejecuta desde index.html, busca status.php en la misma carpeta
        const response = await fetch('status.php'); 
        
        if (!response.ok) throw new Error("Error en la respuesta del servidor");
        const data = await response.json();

        // Obtiene los elementos del HTML
        const serverText = document.getElementById('server-text');
        const serverDot = document.getElementById('server-dot');

        // 1. Actualiza el estado (Online / Offline)
        if (serverText && serverDot) {
            if (data.server === "Online") {
                serverText.innerText = "Online";
                serverText.className = "text-green-500 font-bold tracking-[0.2em]";
                serverDot.className = "w-2.5 h-2.5 rounded-full bg-green-500 shadow-[0_0_8px_#2ecc71] animate-pulse";
            } else {
                serverText.innerText = "Offline";
                serverText.className = "text-red-500 font-bold tracking-[0.2em]";
                serverDot.className = "w-2.5 h-2.5 rounded-full bg-red-500 shadow-[0_0_8px_#e74c3c]";
            }
        }

        // 2. Actualiza las Olympiadas y los 7 Signos
        const olyElement = document.getElementById('oly-time');
        const ssqElement = document.getElementById('ssq-time');
        
        if (olyElement && data.olympiads_remaining) {
            olyElement.innerText = data.olympiads_remaining;
        }
        if (ssqElement && data.seven_signs_remaining) {
            ssqElement.innerText = data.seven_signs_remaining;
        }

    } catch (error) {
        console.error("Fallo al conectar con status.php:", error);
        
        // Si hay error (archivo no encontrado o PHP apagado), forzamos que diga OFFLINE y Error
        const serverText = document.getElementById('server-text');
        const serverDot = document.getElementById('server-dot');
        
        if (serverText && serverDot) {
            serverText.innerText = "Offline";
            serverText.className = "text-red-500 font-bold tracking-[0.2em]";
            serverDot.className = "w-2.5 h-2.5 rounded-full bg-red-500 shadow-[0_0_8px_#e74c3c]";
        }
        
        if (document.getElementById('oly-time')) document.getElementById('oly-time').innerText = "Error";
        if (document.getElementById('ssq-time')) document.getElementById('ssq-time').innerText = "Error";
    }
}

fetchServerStatus();
setInterval(fetchServerStatus, 15000);