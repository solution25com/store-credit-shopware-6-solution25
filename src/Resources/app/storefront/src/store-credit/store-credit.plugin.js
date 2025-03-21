const { PluginBaseClass } = window;

export default class StoreCreditPlugin extends PluginBaseClass {
    init() {
        const input = document.getElementById('storeCreditAmount');
        if (input) {
            input.addEventListener('input', this.checkAmountValidity.bind(this));
        }
    }

    checkAmountValidity(event) {
        const maxAllowedCredit = parseFloat(event.target.getAttribute('max'));
        const button = document.getElementById('applyCreditButton');
        const message = document.getElementById('exceedCreditMessage');
        const enteredAmount = parseFloat(event.target.value);

        if (enteredAmount > maxAllowedCredit) {
            message.style.display = 'block';
            button.disabled = true;
        } else {
            message.style.display = 'none';
            button.disabled = enteredAmount <= 0 || isNaN(enteredAmount);
        }
    }
}
