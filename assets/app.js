import './stimulus_bootstrap.js';

// Une réponse sans <turbo-frame> correspondant (ex. redirection EAN → fiche produit) devient une visite pleine page.
document.addEventListener('turbo:frame-missing', (event) => {
    event.preventDefault();
    event.detail.visit(event.detail.response);
});
