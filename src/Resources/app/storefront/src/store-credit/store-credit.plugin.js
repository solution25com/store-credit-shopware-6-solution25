const { PluginBaseClass } = window;

export default class StoreCreditPlugin extends PluginBaseClass {
    init() {
        this.input = this.el.querySelector('#storeCreditAmount');
        this.button = this.el.querySelector('#applyCreditButton');
        this.message = this.el.querySelector('#exceedCreditMessage');
        if (!this.input || !this.button || !this.message) {
            return;
        }
        this.onInput = this.checkAmountValidity.bind(this);
        this.input.addEventListener('input', this.onInput);
        this.checkAmountValidity();
    }

    checkAmountValidity() {
        const amount = Number(this.input.value);
        const maximum = Number(this.input.max);
        this.message.hidden = !(amount > maximum);
        this.button.disabled = !Number.isFinite(amount) || amount < 0.01 || amount > maximum || !this.input.validity.valid;
    }

    destroy() {
        this.input?.removeEventListener('input', this.onInput);
    }
}
