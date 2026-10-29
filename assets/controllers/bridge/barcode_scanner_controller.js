import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

// Contract with the native shells (lot 3b): component "barcode-scanner",
// event "scan", reply { code: string }; cancelling sends no reply.
export default class extends BridgeComponent {
    static component = 'barcode-scanner';
    static targets = ['nativeButton', 'webButton', 'input'];

    connect() {
        super.connect();

        if (this.enabled) {
            this.nativeButtonTarget.hidden = false;
            this.webButtonTarget.hidden = true;
        }
    }

    scan() {
        this.send('scan', {}, (message) => {
            const code = message?.data?.code;
            if (typeof code !== 'string' || code.trim() === '') {
                return;
            }
            this.inputTarget.value = code;
            this.element.requestSubmit();
        });
    }
}
