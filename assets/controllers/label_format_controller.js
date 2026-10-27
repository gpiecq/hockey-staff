import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'hs.label.format';

// Mémorise le format d'étiquette choisi et masque la première case pour le rouleau.
export default class extends Controller {
    static targets = ['format', 'firstCell'];

    connect() {
        const saved = this.read();
        if (saved && Array.from(this.formatTarget.options).some((option) => option.value === saved)) {
            this.formatTarget.value = saved;
        }
        this.toggle();
    }

    change() {
        this.write(this.formatTarget.value);
        this.toggle();
    }

    toggle() {
        this.firstCellTarget.hidden = this.formatTarget.value === 'rouleau';
    }

    read() {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    write(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // Stockage indisponible : le format n'est simplement pas mémorisé.
        }
    }
}
