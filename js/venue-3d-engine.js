/**
 * ==============================================================================
 * EVENTIA 3D VENUE & INTERACTIVE SEATING ENGINE (js/venue-3d-engine.js)
 * Moteur de rendu 3D temps réel pour salles de spectacle, stades & auditoriums.
 * Permet l'exploration spatiale 3D et le choix de places selon les tarifs.
 * ==============================================================================
 */

class EventiaVenue3D {
    constructor(canvasId, options = {}) {
        this.canvas = typeof canvasId === 'string' ? document.getElementById(canvasId) : canvasId;
        if (!this.canvas) {
            console.error('EventiaVenue3D: Canvas introuvable', canvasId);
            return;
        }

        this.ctx = this.canvas.getContext('2d');
        this.options = Object.assign({
            readOnly: false,
            maxSeats: 10,
            primaryColor: '#0d9488',
            accentColor: '#38bdf8',
            onSeatSelect: null,
            onSeatDeselect: null,
            onHoverSeat: null,
            onTotalChange: null
        }, options);

        // Données du lieu et des sièges
        this.venueData = null;
        this.seats = [];
        this.zones = [];
        this.selectedSeats = new Map(); // seatId -> seatObject
        this.hoveredSeat = null;
        this.activeTariffFilter = 'all'; // 'all' ou ticket_type_id

        // Paramètres de Caméra 3D
        this.camera = {
            pitch: 0.65,       // Inclinaison verticale (rad)
            yaw: 0.0,          // Rotation horizontale (rad)
            targetPitch: 0.65,
            targetYaw: 0.0,
            distance: 540,     // Zoom / Distance
            targetDistance: 540,
            panX: 0,
            panY: 30,
            targetPanX: 0,
            targetPanY: 30,
            fov: 600
        };

        // État des interactions
        this.isDragging = false;
        this.dragButton = 0;
        this.lastMouseX = 0;
        this.lastMouseY = 0;
        this.touchStartDist = 0;

        // Animation loop
        this.animationFrame = null;
        this.pulseTime = 0;

        this.initCanvasSize();
        this.bindEvents();
        this.startLoop();
    }

    initCanvasSize() {
        if (!this.canvas || !this.canvas.parentElement) return;
        const rect = this.canvas.parentElement.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        this.width = rect.width || 800;
        this.height = rect.height || 500;

        this.canvas.width = this.width * dpr;
        this.canvas.height = this.height * dpr;
        this.canvas.style.width = this.width + 'px';
        this.canvas.style.height = this.height + 'px';
        this.ctx.setTransform(1, 0, 0, 1, 0, 0); // reset scale
        this.ctx.scale(dpr, dpr);
    }

    resize() {
        this.initCanvasSize();
    }

    render() {
        this.draw();
    }

    loadVenueData(data) {
        this.venueData = data;
        this.seats = data.seats || [];
        this.zones = data.zones || [];
        this.selectedSeats.clear();
        this.hoveredSeat = null;

        // Positionnement optimal selon le type de modèle 3D
        const modele = data.salle ? data.salle.modele_3d : 'theatre_italien';
        if (modele === 'stade') {
            this.camera.targetDistance = 680;
            this.camera.targetPitch = 0.75;
        } else if (modele === 'arena') {
            this.camera.targetDistance = 580;
            this.camera.targetPitch = 0.70;
        } else {
            this.camera.targetDistance = 520;
            this.camera.targetPitch = 0.62;
        }
        this.camera.targetPanY = 35;
    }

    async loadFromEndpoint(url) {
        try {
            const res = await fetch(url);
            const json = await res.json();
            if (json.success) {
                this.loadVenueData(json);
                return json;
            } else {
                console.error('Erreur API 3D:', json.message);
            }
        } catch (e) {
            console.error('Échec chargement 3D:', e);
        }
        return null;
    }

    bindEvents() {
        const c = this.canvas;

        // Mouse Drag (Orbit & Pan)
        c.addEventListener('mousedown', (e) => {
            this.isDragging = true;
            this.dragButton = e.button;
            this.lastMouseX = e.clientX;
            this.lastMouseY = e.clientY;
            c.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', (e) => {
            const rect = c.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;

            if (this.isDragging) {
                const dx = e.clientX - this.lastMouseX;
                const dy = e.clientY - this.lastMouseY;
                this.lastMouseX = e.clientX;
                this.lastMouseY = e.clientY;

                if (this.dragButton === 0) { // Orbit
                    this.camera.targetYaw -= dx * 0.006;
                    this.camera.targetPitch = Math.max(0.15, Math.min(1.4, this.camera.targetPitch + dy * 0.005));
                } else if (this.dragButton === 2 || e.shiftKey) { // Pan
                    this.camera.targetPanX += dx * 0.8;
                    this.camera.targetPanY += dy * 0.8;
                }
            } else {
                // Raycasting / Hover Detection
                this.handleHover(mouseX, mouseY);
            }
        });

        window.addEventListener('mouseup', () => {
            if (this.isDragging) {
                this.isDragging = false;
                c.style.cursor = 'default';
            }
        });

        c.addEventListener('contextmenu', (e) => e.preventDefault());

        // Zoom Wheel
        c.addEventListener('wheel', (e) => {
            e.preventDefault();
            const zoomDelta = e.deltaY * 0.5;
            this.camera.targetDistance = Math.max(200, Math.min(1100, this.camera.targetDistance + zoomDelta));
        }, { passive: false });

        // Click Selection
        c.addEventListener('click', (e) => {
            if (this.options.readOnly) return;
            const rect = c.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;

            const seat = this.getSeatAtScreen(mouseX, mouseY);
            if (seat && seat.statut === 'libre') {
                this.toggleSeatSelection(seat);
            }
        });

        // Touch gestures for mobile
        c.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                this.isDragging = true;
                this.lastMouseX = e.touches[0].clientX;
                this.lastMouseY = e.touches[0].clientY;
            } else if (e.touches.length === 2) {
                this.isDragging = false;
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                this.touchStartDist = Math.hypot(dx, dy);
            }
        }, { passive: true });

        c.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1 && this.isDragging) {
                const dx = e.touches[0].clientX - this.lastMouseX;
                const dy = e.touches[0].clientY - this.lastMouseY;
                this.lastMouseX = e.touches[0].clientX;
                this.lastMouseY = e.touches[0].clientY;

                this.camera.targetYaw -= dx * 0.007;
                this.camera.targetPitch = Math.max(0.15, Math.min(1.4, this.camera.targetPitch + dy * 0.006));
            } else if (e.touches.length === 2) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                const dist = Math.hypot(dx, dy);
                const delta = (this.touchStartDist - dist) * 1.5;
                this.touchStartDist = dist;
                this.camera.targetDistance = Math.max(200, Math.min(1100, this.camera.targetDistance + delta));
            }
        }, { passive: true });

        c.addEventListener('touchend', (e) => {
            this.isDragging = false;
            if (e.changedTouches.length === 1 && !this.options.readOnly) {
                const rect = c.getBoundingClientRect();
                const touchX = e.changedTouches[0].clientX - rect.left;
                const touchY = e.changedTouches[0].clientY - rect.top;
                const seat = this.getSeatAtScreen(touchX, touchY);
                if (seat && seat.statut === 'libre') {
                    this.toggleSeatSelection(seat);
                }
            }
        });

        // Resize observer
        window.addEventListener('resize', () => {
            this.initCanvasSize();
        });
    }

    startLoop() {
        const render = () => {
            this.updateCamera();
            this.pulseTime += 0.04;
            this.draw();
            this.animationFrame = requestAnimationFrame(render);
        };
        this.animationFrame = requestAnimationFrame(render);
    }

    updateCamera() {
        // Smooth camera lerp
        this.camera.pitch += (this.camera.targetPitch - this.camera.pitch) * 0.12;
        this.camera.yaw += (this.camera.targetYaw - this.camera.yaw) * 0.12;
        this.camera.distance += (this.camera.targetDistance - this.camera.distance) * 0.12;
        this.camera.panX += (this.camera.targetPanX - this.camera.panX) * 0.12;
        this.camera.panY += (this.camera.targetPanY - this.camera.panY) * 0.12;
    }

    project3D(x, y, z) {
        // 1. Rotation Yaw (axe Y vertical)
        const cosY = Math.cos(this.camera.yaw);
        const sinY = Math.sin(this.camera.yaw);
        const rx = x * cosY - z * sinY;
        const rz1 = x * sinY + z * cosY;

        // 2. Rotation Pitch (axe X horizontal)
        const cosP = Math.cos(this.camera.pitch);
        const sinP = Math.sin(this.camera.pitch);
        const ry = y * cosP - rz1 * sinP;
        const rz2 = y * sinP + rz1 * cosP;

        // 3. Translation de distance caméra
        const zDist = rz2 + this.camera.distance;
        if (zDist <= 10) return null; // Derrière la caméra

        // 4. Projection perspective
        const scale = this.camera.fov / zDist;
        const screenX = (this.width / 2) + (rx * scale) + this.camera.panX;
        const screenY = (this.height / 2) - (ry * scale) + this.camera.panY;

        return {
            x: screenX,
            y: screenY,
            scale: scale,
            depth: zDist
        };
    }

    draw() {
        const ctx = this.ctx;
        ctx.clearRect(0, 0, this.width, this.height);

        // 1. Fond immersif avec dégradé radial théâtral
        const bgGrad = ctx.createRadialGradient(
            this.width / 2, this.height * 0.4, 60,
            this.width / 2, this.height / 2, this.width * 0.8
        );
        bgGrad.addColorStop(0, '#0f172a');
        bgGrad.addColorStop(0.6, '#090d16');
        bgGrad.addColorStop(1, '#030712');
        ctx.fillStyle = bgGrad;
        ctx.fillRect(0, 0, this.width, this.height);

        // 2. Dessin du sol / Grille architecturale
        this.drawFloorGrid();

        // 3. Dessin de la Scène 3D & Projecteurs
        this.draw3DStage();

        // 4. Tri et dessin de tous les sièges 3D
        this.drawSeats();

        // 5. Tooltip HUD flottant pour le siège survolé
        if (this.hoveredSeat) {
            this.drawSeatTooltip(this.hoveredSeat);
        }

        // 6. Vue simulée depuis le siège sélectionné
        if (this.selectedSeats.size > 0) {
            this.drawSelectedSightlines();
        }
    }

    drawFloorGrid() {
        const ctx = this.ctx;
        ctx.save();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.04)';
        ctx.lineWidth = 1;

        const gridSize = 350;
        const step = 50;

        for (let x = -gridSize; x <= gridSize; x += step) {
            const p1 = this.project3D(x, -5, -40);
            const p2 = this.project3D(x, -5, gridSize + 120);
            if (p1 && p2) {
                ctx.beginPath();
                ctx.moveTo(p1.x, p1.y);
                ctx.lineTo(p2.x, p2.y);
                ctx.stroke();
            }
        }
        ctx.restore();
    }

    draw3DStage() {
        const ctx = this.ctx;
        ctx.save();

        // Coordonnées du podium de scène en 3D
        const stageWidth = 220;
        const stageDepth = 70;
        const stageHeight = 14;
        const stageZ = -10;

        const pTL = this.project3D(-stageWidth / 2, stageHeight, stageZ - stageDepth / 2);
        const pTR = this.project3D(stageWidth / 2, stageHeight, stageZ - stageDepth / 2);
        const pBR = this.project3D(stageWidth / 2, stageHeight, stageZ + stageDepth / 2);
        const pBL = this.project3D(-stageWidth / 2, stageHeight, stageZ + stageDepth / 2);

        const pbBL = this.project3D(-stageWidth / 2, 0, stageZ + stageDepth / 2);
        const pbBR = this.project3D(stageWidth / 2, 0, stageZ + stageDepth / 2);

        // Face avant du podium
        if (pBL && pBR && pbBR && pbBL) {
            ctx.fillStyle = '#0f172a';
            ctx.beginPath();
            ctx.moveTo(pbBL.x, pbBL.y);
            ctx.lineTo(pBL.x, pBL.y);
            ctx.lineTo(pBR.x, pBR.y);
            ctx.lineTo(pbBR.x, pbBR.y);
            ctx.closePath();
            ctx.fill();
            ctx.strokeStyle = '#1e293b';
            ctx.stroke();
        }

        // Surface supérieure de la scène (Podium)
        if (pTL && pTR && pBR && pBL) {
            const stageGrad = ctx.createLinearGradient(pTL.x, pTL.y, pBR.x, pBR.y);
            stageGrad.addColorStop(0, '#1e293b');
            stageGrad.addColorStop(0.5, '#334155');
            stageGrad.addColorStop(1, '#0f172a');
            ctx.fillStyle = stageGrad;

            ctx.beginPath();
            ctx.moveTo(pTL.x, pTL.y);
            ctx.lineTo(pTR.x, pTR.y);
            ctx.lineTo(pBR.x, pBR.y);
            ctx.lineTo(pBL.x, pBL.y);
            ctx.closePath();
            ctx.fill();

            // Bordure lumineuse néon
            ctx.strokeStyle = '#0d9488';
            ctx.lineWidth = 2.5;
            ctx.stroke();

            // Texte "★ SCÈNE PRINCIPALE / PODIUM ★"
            const pCenter = this.project3D(0, stageHeight, stageZ);
            if (pCenter) {
                ctx.fillStyle = '#f8fafc';
                ctx.font = `bold ${Math.max(10, Math.round(13 * pCenter.scale))}px Inter, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('★ SCÈNE OFFICIELLE ★', pCenter.x, pCenter.y);
            }
        }

        // Faisceaux de projecteurs scéniques (Lighting Beams)
        const beamCenter = this.project3D(0, 140, stageZ - 30);
        if (beamCenter && pBL && pBR) {
            const beamGrad = ctx.createRadialGradient(
                beamCenter.x, beamCenter.y, 5,
                beamCenter.x, beamCenter.y + 120, 180
            );
            beamGrad.addColorStop(0, 'rgba(56, 189, 248, 0.35)');
            beamGrad.addColorStop(0.5, 'rgba(13, 148, 136, 0.12)');
            beamGrad.addColorStop(1, 'rgba(15, 23, 42, 0)');

            ctx.fillStyle = beamGrad;
            ctx.beginPath();
            ctx.moveTo(beamCenter.x - 20, beamCenter.y);
            ctx.lineTo(pBL.x - 40, pBL.y + 40);
            ctx.lineTo(pBR.x + 40, pBR.y + 40);
            ctx.lineTo(beamCenter.x + 20, beamCenter.y);
            ctx.closePath();
            ctx.fill();
        }

        ctx.restore();
    }

    drawSeats() {
        const ctx = this.ctx;

        // Projeter tous les sièges et trier par profondeur (Z-buffering peintre)
        const projectedSeats = [];

        for (let i = 0; i < this.seats.length; i++) {
            const seat = this.seats[i];
            const proj = this.project3D(seat.x, seat.y, seat.z);
            if (proj) {
                projectedSeats.push({
                    seat: seat,
                    proj: proj
                });
            }
        }

        // Tri du plus loin au plus proche
        projectedSeats.sort((a, b) => b.proj.depth - a.proj.depth);

        // Rendu de chaque siège
        for (let i = 0; i < projectedSeats.length; i++) {
            const item = projectedSeats[i];
            this.drawSingleSeat(item.seat, item.proj);
        }
    }

    drawSingleSeat(seat, proj) {
        const ctx = this.ctx;
        const isSelected = this.selectedSeats.has(seat.id);
        const isHovered = (this.hoveredSeat && this.hoveredSeat.id === seat.id);
        const isReserved = (seat.statut !== 'libre');

        // Filtrage par Tarif
        let isMatchingFilter = true;
        if (this.activeTariffFilter !== 'all') {
            isMatchingFilter = (String(seat.ticket_type_id) === String(this.activeTariffFilter));
        }

        const baseRadius = Math.max(3.5, 7.5 * proj.scale);
        const radius = isSelected ? baseRadius * 1.35 : (isHovered ? baseRadius * 1.25 : baseRadius);

        ctx.save();
        ctx.translate(proj.x, proj.y);

        // Couleur selon l'état
        let fillColor = seat.zone_color || '#0d9488';
        let strokeColor = '#ffffff';
        let alpha = isMatchingFilter ? 1.0 : 0.22;

        if (isReserved) {
            fillColor = '#334155';
            strokeColor = '#1e293b';
            alpha = isMatchingFilter ? 0.45 : 0.15;
        } else if (isSelected) {
            fillColor = '#38bdf8';
            strokeColor = '#ffffff';
            alpha = 1.0;
        } else if (isHovered) {
            strokeColor = '#ffffff';
            alpha = 1.0;
        }

        ctx.globalAlpha = alpha;

        // Ombre portée sous le siège 3D
        ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
        ctx.beginPath();
        ctx.ellipse(0, radius * 0.7, radius * 0.95, radius * 0.45, 0, 0, Math.PI * 2);
        ctx.fill();

        // Pulsation si sélectionné
        if (isSelected) {
            const pulseRadius = radius + Math.sin(this.pulseTime * 4) * 3.5;
            ctx.strokeStyle = 'rgba(56, 189, 248, 0.7)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(0, 0, pulseRadius, 0, Math.PI * 2);
            ctx.stroke();
        }

        // Dossier du siège 3D
        ctx.fillStyle = fillColor;
        ctx.beginPath();
        ctx.roundRect(-radius * 0.85, -radius * 1.2, radius * 1.7, radius * 0.8, 3);
        ctx.fill();

        // Assise du siège
        ctx.fillStyle = fillColor;
        ctx.beginPath();
        ctx.arc(0, 0, radius, 0, Math.PI * 2);
        ctx.fill();

        ctx.strokeStyle = strokeColor;
        ctx.lineWidth = isSelected ? 2.5 : (isHovered ? 2 : 1);
        ctx.stroke();

        // Numéro de place si zoom suffisant
        if (proj.scale > 0.85 && (isSelected || isHovered || baseRadius > 8)) {
            ctx.fillStyle = isSelected ? '#0f172a' : '#ffffff';
            ctx.font = `bold ${Math.max(8, Math.round(9 * proj.scale))}px Inter, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(seat.number, 0, 0);
        }

        ctx.restore();
    }

    drawSeatTooltip(seat) {
        const proj = this.project3D(seat.x, seat.y, seat.z);
        if (!proj) return;

        const ctx = this.ctx;
        ctx.save();

        const padding = 12;
        const line1 = `Place ${seat.code} • Rang ${seat.row}`;
        const line2 = `${seat.zone_name} • ${Number(seat.prix).toLocaleString('fr-FR')} FCFA`;
        const line3 = seat.statut === 'libre' ? '✓ Place Disponible' : '✗ Déjà Réservée';

        ctx.font = 'bold 12px Inter, sans-serif';
        const w1 = ctx.measureText(line1).width;
        ctx.font = '11px Inter, sans-serif';
        const w2 = ctx.measureText(line2).width;
        const boxWidth = Math.max(w1, w2, 160) + (padding * 2);
        const boxHeight = 68;

        let boxX = proj.x - (boxWidth / 2);
        let boxY = proj.y - boxHeight - 22;

        // Limites écran
        boxX = Math.max(10, Math.min(this.width - boxWidth - 10, boxX));
        boxY = Math.max(10, boxY);

        // Fond glassmorphism sombre
        ctx.fillStyle = 'rgba(15, 23, 42, 0.94)';
        ctx.strokeStyle = seat.statut === 'libre' ? '#0d9488' : '#ef4444';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.roundRect(boxX, boxY, boxWidth, boxHeight, 10);
        ctx.fill();
        ctx.stroke();

        // Ligne 1 : Titre place
        ctx.fillStyle = '#f8fafc';
        ctx.font = 'bold 12px Inter, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(line1, boxX + padding, boxY + 20);

        // Ligne 2 : Zone et tarif
        ctx.fillStyle = '#38bdf8';
        ctx.font = '600 11px Inter, sans-serif';
        ctx.fillText(line2, boxX + padding, boxY + 38);

        // Ligne 3 : Statut
        ctx.fillStyle = seat.statut === 'libre' ? '#4ade80' : '#f87171';
        ctx.font = 'bold 10px Inter, sans-serif';
        ctx.fillText(line3, boxX + padding, boxY + 54);

        ctx.restore();
    }

    drawSelectedSightlines() {
        const ctx = this.ctx;
        const stageTarget = this.project3D(0, 10, -10);
        if (!stageTarget) return;

        ctx.save();
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.45)';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([4, 4]);

        this.selectedSeats.forEach((seat) => {
            const pSeat = this.project3D(seat.x, seat.y, seat.z);
            if (pSeat) {
                ctx.beginPath();
                ctx.moveTo(pSeat.x, pSeat.y);
                ctx.lineTo(stageTarget.x, stageTarget.y);
                ctx.stroke();
            }
        });

        ctx.restore();
    }

    handleHover(mouseX, mouseY) {
        const seat = this.getSeatAtScreen(mouseX, mouseY);
        if (seat !== this.hoveredSeat) {
            this.hoveredSeat = seat;
            this.canvas.style.cursor = (seat && seat.statut === 'libre') ? 'pointer' : (this.isDragging ? 'grabbing' : 'default');
            if (this.options.onHoverSeat) {
                this.options.onHoverSeat(seat);
            }
        }
    }

    getSeatAtScreen(mouseX, mouseY) {
        let closestSeat = null;
        let minDistanceSq = Infinity;

        for (let i = 0; i < this.seats.length; i++) {
            const seat = this.seats[i];
            const proj = this.project3D(seat.x, seat.y, seat.z);
            if (!proj) continue;

            const radius = Math.max(12, 14 * proj.scale);
            const dx = mouseX - proj.x;
            const dy = mouseY - proj.y;
            const distSq = dx * dx + dy * dy;

            if (distSq <= radius * radius && distSq < minDistanceSq) {
                minDistanceSq = distSq;
                closestSeat = seat;
            }
        }

        return closestSeat;
    }

    toggleSeatSelection(seat) {
        if (!seat || seat.statut !== 'libre') return;

        if (this.selectedSeats.has(seat.id)) {
            this.selectedSeats.delete(seat.id);
            if (this.options.onSeatDeselect) {
                this.options.onSeatDeselect(seat, Array.from(this.selectedSeats.values()));
            }
        } else {
            if (this.selectedSeats.size >= this.options.maxSeats) {
                alert(`Vous pouvez sélectionner au maximum ${this.options.maxSeats} places.`);
                return;
            }
            this.selectedSeats.set(seat.id, seat);
            if (this.options.onSeatSelect) {
                this.options.onSeatSelect(seat, Array.from(this.selectedSeats.values()));
            }
        }

        this.notifyStateChange();
    }

    notifyStateChange() {
        const allSelected = Array.from(this.selectedSeats.values());
        if (this.options.onTotalChange) {
            let total = 0;
            allSelected.forEach(s => total += (Number(s.prix) || 0) + (Number(s.frais_place) || 0));
            this.options.onTotalChange(allSelected, total);
        }
    }

    filterByTariff(ticketTypeId) {
        this.activeTariffFilter = ticketTypeId;

        // Si un tarif spécifique est choisi, orienter la caméra vers la zone correspondante
        if (ticketTypeId !== 'all') {
            const matchingSeat = this.seats.find(s => String(s.ticket_type_id) === String(ticketTypeId));
            if (matchingSeat) {
                if (matchingSeat.position_type === 'vip_avant') {
                    this.camera.targetDistance = 420;
                    this.camera.targetPitch = 0.55;
                    this.camera.targetPanY = 40;
                } else if (matchingSeat.position_type === 'balcon') {
                    this.camera.targetDistance = 580;
                    this.camera.targetPitch = 0.85;
                    this.camera.targetPanY = 10;
                } else {
                    this.camera.targetDistance = 480;
                    this.camera.targetPitch = 0.65;
                }
            }
        } else {
            this.camera.targetDistance = 540;
            this.camera.targetPitch = 0.65;
            this.camera.targetPanX = 0;
            this.camera.targetPanY = 30;
        }
    }

    setViewPreset(preset) {
        if (preset === 'isometric') {
            this.camera.targetPitch = 0.65;
            this.camera.targetYaw = 0.35;
            this.camera.targetDistance = 540;
        } else if (preset === 'top') {
            this.camera.targetPitch = 1.35;
            this.camera.targetYaw = 0.0;
            this.camera.targetDistance = 620;
        } else if (preset === 'stage') {
            this.camera.targetPitch = 0.25;
            this.camera.targetYaw = 0.0;
            this.camera.targetDistance = 440;
        } else if (preset === 'reset') {
            this.camera.targetPitch = 0.65;
            this.camera.targetYaw = 0.0;
            this.camera.targetDistance = 540;
            this.camera.targetPanX = 0;
            this.camera.targetPanY = 30;
        }
    }

    clearSelection() {
        this.selectedSeats.clear();
        this.notifyStateChange();
    }

    destroy() {
        if (this.animationFrame) {
            cancelAnimationFrame(this.animationFrame);
        }
    }
}

// Export global pour utilisation dans les scripts
window.EventiaVenue3D = EventiaVenue3D;
