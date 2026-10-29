import { Controller } from '@hotwired/stimulus';
import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

const FORMATS = [
    BarcodeFormat.EAN_13,
    BarcodeFormat.EAN_8,
    BarcodeFormat.UPC_A,
    BarcodeFormat.UPC_E,
    BarcodeFormat.CODE_128,
    BarcodeFormat.QR_CODE,
];

export default class extends Controller {
    static targets = ['video', 'status', 'input', 'webButton'];

    controls = null;
    starting = false;

    async start() {
        if (this.controls || this.starting) {
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) {
            this.say('Caméra indisponible ici : saisissez le code.');
            return;
        }

        this.starting = true;
        this.say('Démarrage de la caméra…');
        this.videoTarget.hidden = false;

        try {
            const hints = new Map([[DecodeHintType.POSSIBLE_FORMATS, FORMATS]]);
            const reader = new BrowserMultiFormatReader(hints);
            const controls = await reader.decodeFromConstraints(
                { video: { facingMode: 'environment' } },
                this.videoTarget,
                (result) => {
                    if (result) {
                        this.found(result.getText());
                    }
                },
            );
            if (!this.starting) {
                // stop() or disconnect() was called while the camera was starting
                controls.stop();
                this.releaseStream();
                return;
            }
            this.controls = controls;
            this.say('Placez le code dans le cadre.');
        } catch (error) {
            this.stop();
            this.say(
                error && error.name === 'NotAllowedError'
                    ? 'Accès à la caméra refusé : saisissez le code.'
                    : 'Caméra indisponible : saisissez le code.',
            );
        } finally {
            this.starting = false;
        }
    }

    stop() {
        this.starting = false;
        if (this.controls) {
            this.controls.stop();
            this.controls = null;
        }
        this.releaseStream();
        if (this.hasVideoTarget) {
            this.videoTarget.hidden = true;
        }
    }

    disconnect() {
        this.stop();
    }

    found(code) {
        this.stop();
        this.say('');
        this.inputTarget.value = code;
        this.element.requestSubmit();
    }

    releaseStream() {
        if (!this.hasVideoTarget) {
            return;
        }
        const stream = this.videoTarget.srcObject;
        if (stream && typeof stream.getTracks === 'function') {
            stream.getTracks().forEach((track) => track.stop());
        }
        this.videoTarget.srcObject = null;
    }

    say(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}
