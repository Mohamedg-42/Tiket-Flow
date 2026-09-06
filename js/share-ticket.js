/**
 * Partage ou envoi d'un billet au format PDF via WhatsApp
 * Utilise l'API Web Share (avec fichier PDF joint) sur mobile/navigateurs compatibles,
 * avec fallback direct (téléchargement du PDF + ouverture WhatsApp) sur Desktop.
 *
 * Supporte :
 * - Capture visuelle haute fidélité depuis le DOM avec html2pdf si disponible
 * - Téléchargement/génération serveur via fetch(pdfUrl)
 */
async function shareTicketPdfWhatsApp(arg1, arg2, arg3, arg4) {
    let pdfUrl = '';
    let filename = 'billet-eventia.pdf';
    let fallbackMsg = '';
    let targetBtn = null;

    if (arg1 instanceof HTMLElement) {
        targetBtn = arg1;
        pdfUrl = targetBtn.dataset.pdf || targetBtn.getAttribute('data-pdf') || '';
        filename = targetBtn.dataset.filename || targetBtn.getAttribute('data-filename') || 'billet-eventia.pdf';
        fallbackMsg = targetBtn.dataset.message || targetBtn.getAttribute('data-message') || '';
    } else {
        pdfUrl = typeof arg1 === 'string' ? arg1 : '';
        filename = typeof arg2 === 'string' ? arg2 : 'billet-eventia.pdf';
        fallbackMsg = typeof arg3 === 'string' ? arg3 : '';
        targetBtn = arg4 instanceof HTMLElement ? arg4 : (typeof event !== 'undefined' && event?.currentTarget instanceof HTMLElement ? event.currentTarget : null);
    }

    if (!pdfUrl && targetBtn && targetBtn.dataset.pdf) {
        pdfUrl = targetBtn.dataset.pdf;
    }

    const originalHtml = targetBtn ? targetBtn.innerHTML : '';

    if (targetBtn) {
        targetBtn.disabled = true;
        targetBtn.style.pointerEvents = 'none';
        targetBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Génération PDF...';
    }

    let shared = false;
    let pdfBlob = null;

    try {
        // 1. Si le billet est affiché à l'écran (ex: telecharger-ticket.php) et html2pdf est chargé,
        // on capture la carte exacte affichée à l'écran au format PDF haute définition.
        const ticketElem = document.querySelector('.ticket-wrapper') || document.querySelector('.ticket-card');
        if (typeof html2pdf !== 'undefined' && ticketElem) {
            try {
                const opt = {
                    margin:       [8, 8, 8, 8],
                    filename:     filename,
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true, logging: false },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
                };
                pdfBlob = await html2pdf().set(opt).from(ticketElem).output('blob');
            } catch (e) {
                console.warn('html2pdf capture:', e);
            }
        }

        // 2. Si non capturé depuis le DOM, on le récupère depuis le serveur
        if (!pdfBlob && pdfUrl) {
            const response = await fetch(pdfUrl);
            if (response.ok) {
                pdfBlob = await response.blob();
            }
        }

        // 3. Partage via Web Share API sur mobile
        if (pdfBlob) {
            const pdfFile = new File([pdfBlob], filename, { type: 'application/pdf' });

            if (navigator.canShare && navigator.canShare({ files: [pdfFile] })) {
                await navigator.share({
                    files: [pdfFile],
                    title: filename.replace('.pdf', ''),
                    text: fallbackMsg || '🎟️ Voici mon billet Eventia officiel en pièce jointe (PDF).'
                });
                shared = true;
            }
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            shared = true;
        } else {
            console.warn('Erreur lors du partage :', error);
        }
    } finally {
        if (targetBtn) {
            targetBtn.disabled = false;
            targetBtn.style.pointerEvents = 'auto';
            targetBtn.innerHTML = originalHtml;
        }
    }

    if (shared) return;

    // 4. Fallback Desktop (Téléchargement direct du PDF + Ouverture WhatsApp)
    if (pdfBlob) {
        try {
            const blobUrl = URL.createObjectURL(pdfBlob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
        } catch (e) {
            console.error('Erreur téléchargement blob:', e);
        }
    } else if (pdfUrl) {
        try {
            const link = document.createElement('a');
            link.href = pdfUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } catch (e) {
            console.error('Erreur téléchargement url:', e);
        }
    }

    // Ouverture de WhatsApp
    const defaultMsg = fallbackMsg || ('🎟️ Voici mon billet Eventia (PDF) : ' + (pdfUrl ? window.location.origin + '/' + pdfUrl : ''));
    const waUrl = 'https://wa.me/?text=' + encodeURIComponent(defaultMsg);
    window.open(waUrl, '_blank');
}
