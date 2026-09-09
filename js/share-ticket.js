/**
 * Partage ou envoi d'un billet au format PDF via WhatsApp
 * Utilise l'API Web Share (avec fichier PDF joint) sur mobile/navigateurs compatibles,
 * avec téléchargement automatique du fichier et redirection WhatsApp infaillible sur Desktop.
 */
async function shareTicketPdfWhatsApp(arg1, arg2, arg3, arg4) {
    let pdfUrl = '';
    let filename = 'billet-tikeli.pdf';
    let fallbackMsg = '';
    let targetBtn = null;

    if (arg1 instanceof HTMLElement) {
        targetBtn = arg1;
        pdfUrl = targetBtn.dataset.pdf || targetBtn.getAttribute('data-pdf') || '';
        filename = targetBtn.dataset.filename || targetBtn.getAttribute('data-filename') || 'billet-tikeli.pdf';
        fallbackMsg = targetBtn.dataset.message || targetBtn.getAttribute('data-message') || '';
    } else {
        pdfUrl = typeof arg1 === 'string' ? arg1 : '';
        filename = typeof arg2 === 'string' ? arg2 : 'billet-tikeli.pdf';
        fallbackMsg = typeof arg3 === 'string' ? arg3 : '';
        targetBtn = arg4 instanceof HTMLElement ? arg4 : (typeof event !== 'undefined' && event?.currentTarget instanceof HTMLElement ? event.currentTarget : null);
    }

    if (!pdfUrl && targetBtn && targetBtn.dataset.pdf) {
        pdfUrl = targetBtn.dataset.pdf;
    }

    // Résolution de l'URL absolue pour WhatsApp
    let fullPdfUrl = pdfUrl;
    if (pdfUrl && !pdfUrl.startsWith('http://') && !pdfUrl.startsWith('https://')) {
        const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        fullPdfUrl = window.location.origin + basePath + pdfUrl;
    }

    // Préparation de l'URL WhatsApp universelle
    const defaultMsg = fallbackMsg || ('🎟️ *Billet Officiel Tikéli*\n📥 Télécharger mon billet en PDF : ' + fullPdfUrl);
    const waUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(defaultMsg);

    const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);

    // Sur Desktop, on pré-ouvre un onglet pour éviter tout blocage par le bloqueur de popups du navigateur
    let waWindow = null;
    if (!isMobile) {
        try {
            waWindow = window.open('about:blank', '_blank');
        } catch (e) {
            waWindow = null;
        }
    }

    const originalHtml = targetBtn ? targetBtn.innerHTML : '';

    if (targetBtn) {
        targetBtn.disabled = true;
        targetBtn.style.pointerEvents = 'none';
        targetBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Préparation PDF...';
    }

    let shared = false;
    let pdfBlob = null;

    try {
        // 1. Si le billet est affiché à l'écran (ex: telecharger-ticket.php) et html2pdf est chargé
        const ticketElem = document.querySelector('.ticket-wrapper') || document.querySelector('.ticket-card');
        if (typeof html2pdf !== 'undefined' && ticketElem) {
            try {
                const opt = {
                    margin: [8, 8, 8, 8],
                    filename: filename,
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
                };
                pdfBlob = await html2pdf().set(opt).from(ticketElem).output('blob');
            } catch (e) {
                console.warn('html2pdf capture:', e);
            }
        }

        // 2. Si non capturé depuis le DOM, récupération depuis le serveur
        if (!pdfBlob && pdfUrl) {
            const response = await fetch(pdfUrl);
            if (response.ok) {
                pdfBlob = await response.blob();
            }
        }

        // 3. Partage natif via Web Share API sur mobile avec fichier PDF attaché
        if (pdfBlob && navigator.canShare) {
            try {
                const pdfFile = new File([pdfBlob], filename, { type: 'application/pdf' });
                if (navigator.canShare({ files: [pdfFile] })) {
                    await navigator.share({
                        files: [pdfFile],
                        title: filename.replace('.pdf', ''),
                        text: fallbackMsg || ('🎟️ Voici mon billet Tikéli officiel en pièce jointe (PDF).\n📥 Lien de secours : ' + fullPdfUrl)
                    });
                    shared = true;
                }
            } catch (shareErr) {
                if (shareErr.name === 'AbortError') {
                    shared = true; // Annulation utilisateur volontaire
                } else {
                    console.warn("Web Share API files ignoré :", shareErr);
                }
            }
        }
    } catch (error) {
        console.warn('Erreur lors de la préparation du fichier :', error);
    } finally {
        if (targetBtn) {
            targetBtn.disabled = false;
            targetBtn.style.pointerEvents = 'auto';
            targetBtn.innerHTML = originalHtml;
        }
    }

    // Si partagé avec succès sur mobile, fermer l'éventuelle fenêtre pré-ouverte et terminer
    if (shared) {
        if (waWindow && !waWindow.closed) {
            waWindow.close();
        }
        return;
    }

    // 4. Fallback Desktop / Mobile standard :
    // A. Téléchargement direct du fichier PDF
    if (pdfBlob) {
        try {
            const blobUrl = URL.createObjectURL(pdfBlob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 3000);
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

    // B. Ouverture de WhatsApp garantie sans blocage popup
    if (waWindow && !waWindow.closed) {
        waWindow.location.href = waUrl;
    } else {
        const opened = window.open(waUrl, '_blank');
        if (!opened || opened.closed || typeof opened.closed === 'undefined') {
            window.location.href = waUrl;
        }
    }
}
