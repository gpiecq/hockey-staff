import { Controller } from '@hotwired/stimulus';

// Changement de lieu : recharge le Turbo Frame du formulaire pour réafficher les états disponibles dans ce lieu.
export default class extends Controller {
    change(event) {
        const frame = this.element.closest('turbo-frame');
        if (!frame) {
            return;
        }
        const url = new URL(frame.dataset.reloadUrl || window.location.href, window.location.origin);
        url.searchParams.set('lieu', event.target.value);
        frame.src = url.toString();
    }
}
