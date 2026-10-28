import { Controller } from '@hotwired/stimulus';

// Bascule joueur / équipe : masque le champ du bénéficiaire non choisi.
// Sans JavaScript, les deux champs restent visibles.
export default class extends Controller {
    static targets = ['player', 'team'];

    connect() {
        this.toggle();
    }

    toggle() {
        const checked = this.element.querySelector('input[name$="[recipient]"]:checked');
        const recipient = checked ? checked.value : 'player';
        this.playerTarget.hidden = recipient !== 'player';
        this.teamTarget.hidden = recipient !== 'team';
    }
}
