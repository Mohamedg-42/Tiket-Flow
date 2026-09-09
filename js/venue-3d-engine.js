/**
 * ==============================================================================
 * TIKÉLI 3D VENUE & INTERACTIVE SEATING ENGINE (js/venue-3d-engine.js)
 * Moteur de rendu 3D temps réel pour salles de spectacle, stades & auditoriums.
 * Permet l'exploration spatiale 3D et le choix de places selon les tarifs.
 * ==============================================================================
 */

class TikéliVenue3D {
    constructor(canvasId, options = {}) {
        this.canvas = typeof canvasId === 'string' ? document.getElementById(canvasId) : canvasId;
        if (!this.canvas) {
            console.error('TikéliVenue3D: Canvas introuvable', canvasId);
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
            pitch: 0.55,       // Inclinaison verticale (rad) — plus plat pour mieux voir les rangées
            yaw: 0.0,          // Rotation horizontale (rad)
            targetPitch: 0.55,
            targetYaw: 0.0,
            distance: 380,     // Zoom / Distance — plus proche pour des sièges bien visibles
            targetDistance: 380,
            panX: 0,
            panY: 20,
            targetPanX: 0,
            targetPanY: 20,
            fov: 600
        };

        // État des interactions
        this.isDragging = false;
        this.dragButton = 0;
        this.lastMouseX = 0;
        this.lastMouseY = 0;
        this.touchStartDist = 0;

        // Animation loop & effets visuels
        this.animationFrame = null;
        this.pulseTime = 0;
        this.burstParticles = [];

        this.initCanvasSize();
        this.bindEvents();
        this.startLoop();
    }

    initCanvasSize() {
        if (!this.canvas) return;
        const parent = this.canvas.parentElement;
        const rect = parent ? parent.getBoundingClientRect() : null;
        const dpr = window.devicePixelRatio || 1;

        const parentW = (rect && rect.width > 50) ? rect.width : (parent ? parent.clientWidth : 0);
        const parentH = (rect && rect.height > 50) ? rect.height : (parent ? parent.clientHeight : 0);

        this.width = Math.max(300, Math.round(parentW || 800));
        this.height = Math.max(300, Math.round(parentH || 500));
        this.isMobile = (this.width < 500);

        this.canvas.width = Math.round(this.width * dpr);
        this.canvas.height = Math.round(this.height * dpr);
        this.canvas.style.width = this.width + 'px';
        this.canvas.style.height = this.height + 'px';

        if (this.ctx) {
            this.ctx.setTransform(1, 0, 0, 1, 0, 0); // reset scale
            this.ctx.scale(dpr, dpr);
        }

        // Observer les redimensionnements du conteneur parent
        if (parent && window.ResizeObserver && !this.resizeObserver) {
            this.resizeObserver = new ResizeObserver(() => {
                this.initCanvasSize();
                this.draw();
            });
            this.resizeObserver.observe(parent);
        }

        // Ajuster la caméra automatiquement pour les petits écrans
        if (this.isMobile) {
            this.camera.targetDistance = Math.min(this.camera.targetDistance, 320);
            this.camera.targetPanY = 0;
        }
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
            this.camera.targetDistance = this.isMobile ? 460 : 540;
            this.camera.targetPitch = 0.75;
            this.camera.targetPanY = this.isMobile ? 10 : 30;
        } else if (modele === 'arena') {
            this.camera.targetDistance = this.isMobile ? 400 : 460;
            this.camera.targetPitch = 0.72;
            this.camera.targetPanY = this.isMobile ? 15 : 25;
        } else {
            this.camera.targetDistance = this.isMobile ? 360 : 420;
            this.camera.targetPitch = this.isMobile ? 0.70 : 0.72;
            this.camera.targetPanY = this.isMobile ? 15 : 25;
        }
        this.camera.targetYaw = 0;
        this.camera.targetPanX = 0;

        // Intro cinématique : départ en plongée aérienne douce qui glisse vers la vue optimale
        this.triggerIntroAnimation();
    }

    triggerIntroAnimation() {
        this.camera.distance = this.camera.targetDistance + 160;
        this.camera.pitch = Math.min(1.25, this.camera.targetPitch + 0.32);
        this.camera.yaw = 0.32;
        this.camera.panY = this.camera.targetPanY - 20;
        this.camera.panX = 12;
    }

    async loadFromEndpoint(url) {
        try {
            let res = await fetch(url, { cache: 'no-store' });
            if (!res.ok) {
                // Fallback attempt: invert ../ prefix if path is relative
                const altUrl = url.startsWith('../') ? url.replace(/^\.\.\//, '') : ('../' + url);
                try {
                    const altRes = await fetch(altUrl, { cache: 'no-store' });
                    if (altRes.ok) res = altRes;
                } catch (_) {}
            }
            const json = await res.json();
            if (json && json.success) {
                this.loadVenueData(json);
                return json;
            } else {
                console.error('Erreur API 3D:', json ? json.message : 'Réponse invalide');
                return json;
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
            try {
                this.updateCamera();
                this.pulseTime += 0.04;
                this.draw();
            } catch (err) {
                console.warn('Rendu 3D loop warning:', err);
            }
            this.animationFrame = requestAnimationFrame(render);
        };
        this.animationFrame = requestAnimationFrame(render);
    }

    updateCamera() {
        // Smooth camera lerp avec amortissement soyeux
        const lerp = 0.09;
        this.camera.pitch += (this.camera.targetPitch - this.camera.pitch) * lerp;
        this.camera.yaw += (this.camera.targetYaw - this.camera.yaw) * lerp;
        this.camera.distance += (this.camera.targetDistance - this.camera.distance) * lerp;
        this.camera.panX += (this.camera.targetPanX - this.camera.panX) * lerp;
        this.camera.panY += (this.camera.targetPanY - this.camera.panY) * lerp;
    }

    project3D(x, y, z) {
        x = Number(x) || 0;
        y = Number(y) || 0;
        z = Number(z) || 0;

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
        if (!ctx) return;
        ctx.clearRect(0, 0, this.width, this.height);

        const w = Math.max(100, this.width || 800);
        const h = Math.max(100, this.height || 500);

        // 1. Fond immersif avec dégradé radial théâtral
        const bgGrad = ctx.createRadialGradient(
            w / 2, h * 0.4, 60,
            w / 2, h / 2, Math.max(100, w * 0.8)
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

        // 4b. Particules d'ondes de choc (Bursts cinétiques de sélection)
        this.drawBursts();

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

        const t = this.pulseTime;

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

            // Bandeau LED animé sur le nez de scène (front runner)
            const ledGrad = ctx.createLinearGradient(pBL.x, pBL.y, pBR.x, pBR.y);
            const cycle = (Math.sin(t * 2.2) + 1) / 2;
            ledGrad.addColorStop(0, '#0d9488');
            ledGrad.addColorStop(Math.max(0.01, Math.min(0.99, cycle)), '#38bdf8');
            ledGrad.addColorStop(1, '#FF4A0D');
            ctx.strokeStyle = ledGrad;
            ctx.lineWidth = 2.5;
            ctx.beginPath();
            ctx.moveTo(pBL.x, pBL.y);
            ctx.lineTo(pBR.x, pBR.y);
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

            // Bordure lumineuse émeraude
            ctx.strokeStyle = 'rgba(13, 148, 136, 0.85)';
            ctx.lineWidth = 2;
            ctx.stroke();

            // Texte "★ SCÈNE OFFICIELLE ★" avec éclat doux
            const pCenter = this.project3D(0, stageHeight, stageZ);
            if (pCenter) {
                ctx.fillStyle = '#f8fafc';
                ctx.font = `bold ${Math.max(10, Math.round(13 * pCenter.scale))}px Inter, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('★ SCÈNE OFFICIELLE ★', pCenter.x, pCenter.y);
            }
        }

        // 1. Faisceau Central Ambiant (Doux et pulsant)
        const beamCenter = this.project3D(0, 150, stageZ - 40);
        if (beamCenter && pBL && pBR) {
            const beamGrad = ctx.createRadialGradient(
                beamCenter.x, beamCenter.y, 10,
                beamCenter.x, beamCenter.y + 130, 210
            );
            const intensity = 0.28 + Math.sin(t * 1.8) * 0.08;
            beamGrad.addColorStop(0, `rgba(56, 189, 248, ${intensity})`);
            beamGrad.addColorStop(0.5, 'rgba(13, 148, 136, 0.10)');
            beamGrad.addColorStop(1, 'rgba(15, 23, 42, 0)');

            ctx.fillStyle = beamGrad;
            ctx.beginPath();
            ctx.moveTo(beamCenter.x - 24, beamCenter.y);
            ctx.lineTo(pBL.x - 35, pBL.y + 35);
            ctx.lineTo(pBR.x + 35, pBR.y + 35);
            ctx.lineTo(beamCenter.x + 24, beamCenter.y);
            ctx.closePath();
            ctx.fill();
        }

        // 2. Projecteur mobile Gauche (Spotlight balayant de gauche à droite)
        const spotLeftOrig = this.project3D(-75, 140, stageZ - 25);
        const sweepTargetX1 = Math.sin(t * 1.1) * 85;
        const spotLeftTarget = this.project3D(sweepTargetX1, 0, stageZ + 30);
        if (spotLeftOrig && spotLeftTarget) {
            const spotGrad = ctx.createLinearGradient(spotLeftOrig.x, spotLeftOrig.y, spotLeftTarget.x, spotLeftTarget.y);
            spotGrad.addColorStop(0, 'rgba(56, 189, 248, 0.35)');
            spotGrad.addColorStop(0.7, 'rgba(13, 148, 136, 0.10)');
            spotGrad.addColorStop(1, 'rgba(56, 189, 248, 0)');

            ctx.fillStyle = spotGrad;
            ctx.beginPath();
            ctx.moveTo(spotLeftOrig.x - 9, spotLeftOrig.y);
            ctx.lineTo(spotLeftTarget.x - 28, spotLeftTarget.y);
            ctx.lineTo(spotLeftTarget.x + 28, spotLeftTarget.y);
            ctx.lineTo(spotLeftOrig.x + 9, spotLeftOrig.y);
            ctx.closePath();
            ctx.fill();
        }

        // 3. Projecteur mobile Droit (Spotlight balayant en opposition de phase)
        const spotRightOrig = this.project3D(75, 140, stageZ - 25);
        const sweepTargetX2 = Math.cos(t * 0.95) * -85;
        const spotRightTarget = this.project3D(sweepTargetX2, 0, stageZ + 30);
        if (spotRightOrig && spotRightTarget) {
            const spotGrad2 = ctx.createLinearGradient(spotRightOrig.x, spotRightOrig.y, spotRightTarget.x, spotRightTarget.y);
            spotGrad2.addColorStop(0, 'rgba(255, 74, 13, 0.28)');
            spotGrad2.addColorStop(0.7, 'rgba(245, 158, 11, 0.08)');
            spotGrad2.addColorStop(1, 'rgba(255, 74, 13, 0)');

            ctx.fillStyle = spotGrad2;
            ctx.beginPath();
            ctx.moveTo(spotRightOrig.x - 9, spotRightOrig.y);
            ctx.lineTo(spotRightTarget.x - 26, spotRightTarget.y);
            ctx.lineTo(spotRightTarget.x + 26, spotRightTarget.y);
            ctx.lineTo(spotRightOrig.x + 9, spotRightOrig.y);
            ctx.closePath();
            ctx.fill();
        }

        // 4. Particules atmosphériques flottantes (Haze dust motes)
        for (let i = 0; i < 7; i++) {
            const seed = i * 47.13;
            const partX = Math.sin(t * 0.7 + seed) * (stageWidth * 0.45);
            const partY = 25 + ((t * 14 + i * 20) % 110);
            const partZ = stageZ + Math.cos(t * 0.5 + seed) * 35;
            const pPart = this.project3D(partX, partY, partZ);
            if (pPart) {
                const pAlpha = 0.25 * (1 - (partY / 135));
                ctx.fillStyle = `rgba(255, 255, 255, ${Math.max(0, pAlpha)})`;
                ctx.beginPath();
                ctx.arc(pPart.x, pPart.y, Math.max(1, 1.7 * pPart.scale), 0, Math.PI * 2);
                ctx.fill();
            }
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
        const isCurrent = (seat.statut === 'actuelle' || seat.is_current);
        const isReserved = (seat.statut !== 'libre' && !isCurrent);

        // Filtrage par Tarif
        let isMatchingFilter = true;
        if (this.activeTariffFilter !== 'all') {
            isMatchingFilter = (String(seat.ticket_type_id) === String(this.activeTariffFilter));
        }

        const baseRadius = Math.max(6, 14 * proj.scale);
        const radius = isSelected ? baseRadius * 1.4 : (isHovered ? baseRadius * 1.3 : baseRadius);

        ctx.save();
        ctx.translate(proj.x, proj.y);

        // Couleur selon l'état
        let fillColor = seat.zone_color || '#0d9488';
        let strokeColor = 'rgba(255,255,255,0.8)';
        let alpha = isMatchingFilter ? 1.0 : 0.22;

        if (isCurrent) {
            fillColor = '#0284c7';
            strokeColor = '#38bdf8';
            alpha = 1.0;
        } else if (isReserved) {
            fillColor = '#475569';
            strokeColor = '#334155';
            alpha = isMatchingFilter ? 0.55 : 0.18;
        } else if (isSelected) {
            fillColor = '#f59e0b';
            strokeColor = '#ffffff';
            alpha = 1.0;
        } else if (isHovered) {
            strokeColor = '#ffffff';
            alpha = 1.0;
        }

        ctx.globalAlpha = alpha;

        // Ombre portée sous le siège 3D
        ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
        ctx.beginPath();
        ctx.ellipse(0, radius * 0.6, radius * 1.1, radius * 0.5, 0, 0, Math.PI * 2);
        ctx.fill();

        // Halo lumineux animé pour les places libres (respiration douce & subtile)
        if (!isReserved && isMatchingFilter) {
            const breath = 0.12 + Math.sin(this.pulseTime * 2.2 + (seat.id % 5)) * 0.04;
            ctx.fillStyle = fillColor;
            ctx.globalAlpha = alpha * breath;
            ctx.beginPath();
            ctx.arc(0, 0, radius * 1.7, 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha = alpha;
        }

        // Siège survolé : micro-rebond et aura lumineuse
        if (isHovered && !isReserved) {
            const hoverHalo = radius * 1.55 + Math.sin(this.pulseTime * 6) * 2;
            ctx.fillStyle = 'rgba(255, 255, 255, 0.22)';
            ctx.beginPath();
            ctx.arc(0, 0, hoverHalo, 0, Math.PI * 2);
            ctx.fill();
        }

        // Pulsation harmonique & onde de choc si sélectionné
        if (isSelected) {
            // 1. Anneau harmonique oscillant
            const pulseRadius = radius + Math.sin(this.pulseTime * 4.5) * 3.5;
            ctx.strokeStyle = 'rgba(255, 74, 13, 0.9)';
            ctx.lineWidth = 2.5;
            ctx.beginPath();
            ctx.arc(0, 0, pulseRadius, 0, Math.PI * 2);
            ctx.stroke();

            // 2. Onde expansive continue (Ripple)
            const rippleProgress = (this.pulseTime * 1.4) % 1;
            const rippleR = radius + (rippleProgress * 16);
            const rippleAlpha = Math.max(0, (1 - rippleProgress) * 0.75);
            ctx.strokeStyle = `rgba(255, 74, 13, ${rippleAlpha})`;
            ctx.lineWidth = 1.6;
            ctx.beginPath();
            ctx.arc(0, 0, rippleR, 0, Math.PI * 2);
            ctx.stroke();
        }

        // Dossier du siège 3D (forme rectangulaire arrondie avec fallback)
        ctx.fillStyle = fillColor;
        ctx.beginPath();
        const safeR = Math.max(1, radius);
        if (typeof ctx.roundRect === 'function') {
            ctx.roundRect(-safeR * 0.85, -safeR * 1.35, safeR * 1.7, safeR * 0.9, Math.min(4, safeR * 0.4));
        } else {
            ctx.rect(-safeR * 0.85, -safeR * 1.35, safeR * 1.7, safeR * 0.9);
        }
        ctx.fill();

        // Assise du siège (cercle principal)
        ctx.fillStyle = fillColor;
        ctx.beginPath();
        ctx.arc(0, 0, safeR, 0, Math.PI * 2);
        ctx.fill();

        // Reflet de lumière sur l'assise
        if (!isReserved) {
            const highlightGrad = ctx.createRadialGradient(-safeR * 0.3, -safeR * 0.3, 0, 0, 0, safeR);
            highlightGrad.addColorStop(0, 'rgba(255, 255, 255, 0.35)');
            highlightGrad.addColorStop(0.5, 'rgba(255, 255, 255, 0.08)');
            highlightGrad.addColorStop(1, 'rgba(255, 255, 255, 0)');
            ctx.fillStyle = highlightGrad;
            ctx.beginPath();
            ctx.arc(0, 0, safeR, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.strokeStyle = strokeColor;
        ctx.lineWidth = isSelected ? 3 : (isHovered ? 2.5 : 1.5);
        ctx.stroke();

        // Numéro de place — visible si le siège a une taille suffisante ou est ciblé
        if ((isSelected || isHovered || baseRadius >= 8) && proj.scale > 0.55) {
            ctx.fillStyle = isSelected ? '#0f172a' : '#ffffff';
            ctx.font = `bold ${Math.max(8, Math.round(11 * proj.scale))}px Inter, sans-serif`;
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
        const isCurrent = (seat.statut === 'actuelle' || seat.is_current);
        const line2 = `${seat.zone_name} • ${Number(seat.prix).toLocaleString('fr-FR')} FCFA`;
        const line3 = isCurrent ? '★ Votre Place Actuelle' : (seat.statut === 'libre' ? '✓ Place Disponible' : '✗ Déjà Réservée');

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
        ctx.strokeStyle = isCurrent ? '#38bdf8' : (seat.statut === 'libre' ? '#0d9488' : '#ef4444');
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
        ctx.fillStyle = '#94a3b8';
        ctx.font = '11px Inter, sans-serif';
        ctx.fillText(line2, boxX + padding, boxY + 38);

        // Ligne 3 : Statut
        ctx.fillStyle = isCurrent ? '#38bdf8' : (seat.statut === 'libre' ? '#4ade80' : '#f87171');
        ctx.font = 'bold 11px Inter, sans-serif';
        ctx.fillText(line3, boxX + padding, boxY + 54);

        ctx.restore();
    }

    drawSelectedSightlines() {
        const ctx = this.ctx;
        const stageTarget = this.project3D(0, 12, -10);
        if (!stageTarget) return;

        ctx.save();

        // 1. Point focal dynamique sur la scène (Impact laser)
        const focusPulse = 4.5 + Math.sin(this.pulseTime * 5) * 1.8;
        const focusGlow = ctx.createRadialGradient(stageTarget.x, stageTarget.y, 1, stageTarget.x, stageTarget.y, focusPulse * 3.5);
        focusGlow.addColorStop(0, 'rgba(56, 189, 248, 0.95)');
        focusGlow.addColorStop(0.4, 'rgba(13, 148, 136, 0.4)');
        focusGlow.addColorStop(1, 'rgba(13, 148, 136, 0)');
        ctx.fillStyle = focusGlow;
        ctx.beginPath();
        ctx.arc(stageTarget.x, stageTarget.y, focusPulse * 3.5, 0, Math.PI * 2);
        ctx.fill();

        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(stageTarget.x, stageTarget.y, focusPulse * 0.7, 0, Math.PI * 2);
        ctx.fill();

        // 2. Faisceaux laser animés avec flux continu vers la scène (lineDashOffset)
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 1.8;
        ctx.setLineDash([7, 5]);
        ctx.lineDashOffset = -this.pulseTime * 24;

        this.selectedSeats.forEach((seat) => {
            const pSeat = this.project3D(seat.x, seat.y, seat.z);
            if (pSeat) {
                // Faisceau principal animé
                ctx.beginPath();
                ctx.moveTo(pSeat.x, pSeat.y);
                ctx.lineTo(stageTarget.x, stageTarget.y);
                ctx.stroke();

                // Lueur secondaire pour donner l'effet laser volumétrique
                ctx.save();
                ctx.strokeStyle = 'rgba(56, 189, 248, 0.22)';
                ctx.lineWidth = 4.5;
                ctx.stroke();
                ctx.restore();
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

    getSeatAtScreen(screenX, screenY) {
        if (!this.seats || this.seats.length === 0) return null;

        let closestSeat = null;
        let minDistance = Infinity;

        for (let i = 0; i < this.seats.length; i++) {
            const seat = this.seats[i];
            const proj = this.project3D(seat.x, seat.y, seat.z);
            if (!proj) continue;

            const dx = screenX - proj.x;
            const dy = screenY - proj.y;
            const dist = Math.sqrt(dx * dx + dy * dy);

            const hitRadius = Math.max(16, 24 * proj.scale);
            if (dist <= hitRadius && dist < minDistance) {
                minDistance = dist;
                closestSeat = seat;
            }
        }

        return closestSeat;
    }

    addSeatBurst(seat, isDeselect = false) {
        if (!seat) return;
        this.burstParticles.push({
            x: seat.x,
            y: seat.y,
            z: seat.z,
            color: isDeselect ? '#94a3b8' : (seat.zone_color || '#FF4A0D'),
            radius: 8,
            maxRadius: 36,
            opacity: 1.0,
            createdAt: performance.now(),
            duration: 460
        });
    }

    drawBursts() {
        if (!this.burstParticles || this.burstParticles.length === 0) return;
        const now = performance.now();
        const ctx = this.ctx;

        this.burstParticles = this.burstParticles.filter(p => {
            const elapsed = now - p.createdAt;
            if (elapsed > p.duration) return false;
            const progress = elapsed / p.duration;
            const proj = this.project3D(p.x, p.y, p.z);
            if (!proj) return true;

            const r = (p.radius + (p.maxRadius - p.radius) * Math.sin(progress * Math.PI / 2)) * proj.scale;
            const alpha = (1 - progress) * p.opacity;

            ctx.save();
            ctx.strokeStyle = p.color;
            ctx.globalAlpha = Math.max(0, alpha);
            ctx.lineWidth = Math.max(1, 3 * (1 - progress));
            ctx.beginPath();
            ctx.arc(proj.x, proj.y, r, 0, Math.PI * 2);
            ctx.stroke();

            // Deuxième anneau éthéré
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = Math.max(0.5, 1.5 * (1 - progress));
            ctx.beginPath();
            ctx.arc(proj.x, proj.y, Math.max(1, r * 0.7), 0, Math.PI * 2);
            ctx.stroke();

            ctx.restore();
            return true;
        });
    }

    toggleSeatSelection(seat) {
        if (!seat || seat.statut !== 'libre') return;

        if (this.selectedSeats.has(seat.id)) {
            this.addSeatBurst(seat, true);
            this.selectedSeats.delete(seat.id);
            if (this.options.onSeatDeselect) {
                this.options.onSeatDeselect(seat, Array.from(this.selectedSeats.values()));
            }
        } else {
            if (this.options.maxSeats === 1 && this.selectedSeats.size >= 1) {
                // Remplacement automatique pour sélection d'un seul siège (changement de place)
                const prev = Array.from(this.selectedSeats.values())[0];
                if (prev) this.addSeatBurst(prev, true);
                this.selectedSeats.clear();
            } else if (this.selectedSeats.size >= this.options.maxSeats) {
                alert(`Vous pouvez sélectionner au maximum ${this.options.maxSeats} places.`);
                return;
            }
            this.addSeatBurst(seat, false);
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
                    this.camera.targetDistance = 320;
                    this.camera.targetPitch = 0.50;
                    this.camera.targetPanY = 25;
                } else if (matchingSeat.position_type === 'balcon') {
                    this.camera.targetDistance = 440;
                    this.camera.targetPitch = 0.75;
                    this.camera.targetPanY = 10;
                } else {
                    this.camera.targetDistance = 360;
                    this.camera.targetPitch = 0.55;
                }
            }
        } else {
            this.camera.targetDistance = 380;
            this.camera.targetPitch = 0.55;
            this.camera.targetPanX = 0;
            this.camera.targetPanY = 20;
        }
    }

    setViewPreset(preset) {
        if (preset === 'isometric') {
            this.camera.targetPitch = 0.55;
            this.camera.targetYaw = 0.35;
            this.camera.targetDistance = 400;
        } else if (preset === 'top') {
            this.camera.targetPitch = 1.30;
            this.camera.targetYaw = 0.0;
            this.camera.targetDistance = 480;
        } else if (preset === 'stage') {
            this.camera.targetPitch = 0.22;
            this.camera.targetYaw = 0.0;
            this.camera.targetDistance = 340;
        } else if (preset === 'reset') {
            this.camera.targetPitch = 0.55;
            this.camera.targetYaw = 0.0;
            this.camera.targetDistance = 380;
            this.camera.targetPanX = 0;
            this.camera.targetPanY = 20;
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
window.TikéliVenue3D = TikéliVenue3D;
window.TikeliVenue3D = TikéliVenue3D;
window.EventiaVenue3D = TikéliVenue3D;
if (typeof EventiaVenue3D === 'undefined') {
    var EventiaVenue3D = TikéliVenue3D;
}
if (typeof TikeliVenue3D === 'undefined') {
    var TikeliVenue3D = TikéliVenue3D;
}
