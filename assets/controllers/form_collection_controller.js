import { Controller } from '@hotwired/stimulus';

/*
 * Collection de sous-formulaires : ajoute une ligne à partir du data-prototype
 * (remplace __name__ par un index croissant) et retire une ligne.
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { index: Number };

    add(event) {
        event.preventDefault();
        const html = this.listTarget.dataset.prototype.replaceAll('__name__', String(this.indexValue));
        this.indexValue += 1;
        this.listTarget.insertAdjacentHTML('beforeend', html);
    }

    remove(event) {
        event.preventDefault();
        event.target.closest('[data-form-collection-item]').remove();
    }
}
