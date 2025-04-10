import Plugin from 'src/plugin-system/plugin.class';

export default class StoreCredit extends Plugin {
    init() {
        this.inputField = this.el.querySelector('#storeCreditAmount');
        this.button = this.el.querySelector('#applyCreditButton');
        this.message = this.el.querySelector('#exceedCreditMessage');

        this.maxAllowedCredit = parseFloat(this.inputField.dataset.maxCredit);

        this.registerEvents();
    }

    registerEvents() {
        this.inputField.addEventListener('input', this.checkAmountValidity.bind(this));
    }

    checkAmountValidity(event) {

        const enteredAmount = parseFloat(event.target.value);

        if (enteredAmount > this.maxAllowedCredit) {
            this.message.style.display = 'block';
            this.button.disabled = true;
        } else {
            this.message.style.display = 'none';
            this.button.disabled = enteredAmount <= 0 || isNaN(enteredAmount);
        }
    }
}
