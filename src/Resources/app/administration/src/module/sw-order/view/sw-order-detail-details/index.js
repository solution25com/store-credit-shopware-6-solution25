import { Component } from 'Shopware';

Component.override('sw-order-detail-details', {
    mounted() {
        this.applyFieldRestrictions();
    },

    watch: {
        'order.customFields.orderTypePayment'() {
            this.applyFieldRestrictions();
        }
    },

    methods: {
        applyFieldRestrictions() {
            this.$nextTick(() => {
                const orderTypePayment = this.order?.customFields?.orderTypePayment;
                console.log("Selected Order Payment Type:", orderTypePayment);

                this.$el.classList.remove('cc-payment', 'ach-echeck-payment');

                if (orderTypePayment === "credit_card") {
                    console.log("CC Payment detected, disabling specific fields.");
                    this.$el.classList.add('cc-payment');
                    this.disableFields([
                        'sw-order-detail-details__billing-address',
                        'sw-order-detail-details__shipping-address' // Added this
                    ]);
                } else if (orderTypePayment === "ach_echeck") {
                    console.log("ACH eCheck detected, disabling specific fields.");
                    this.$el.classList.add('ach-echeck-payment');
                    this.disableFields([
                        'sw-order-detail-details__billing-address',
                        'sw-order-detail-details__shipping-address', // Added this
                        'sw-order-detail-details__phone-number'
                    ]);
                }
            });
        },

        disableFields(selectors) {
            selectors.forEach(selector => {
                const field = this.$el.querySelector(`.${selector}`);
                if (field) {
                    field.querySelectorAll('input, textarea, select, button').forEach(el => el.setAttribute('disabled', 'disabled'));
                }
            });
        }
    }
});