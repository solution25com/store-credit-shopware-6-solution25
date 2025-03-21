const { Component } = Shopware;
const { mapState } = Shopware.Component.getComponentHelper();

Component.override('sw-order-detail', {
    data() {
        return {
            originalZipCode: null
        };
    },

    computed: {
        ...mapState('swOrderDetail', ['order']),

        orderRepository() {
            return Shopware.Service('repositoryFactory').create('order');
        },

        orderTypePayment() {
            return this.order?.customFields?.orderTypePayment ?? 'other';
        },

        isCreditCardOrder() {
            return this.orderTypePayment === 'credit_card';
        },

        isDetailsTabActive() {
            return this.$route.name === 'sw.order.detail.details';
        },

        shippingZipCode() {
            return this.order?.deliveries?.[0]?.shippingOrderAddress?.zipcode ?? null;
        }
    },

    watch: {
        order: {
            handler(newOrder) {
                if (newOrder && !this.originalZipCode) {
                    this.originalZipCode = this.shippingZipCode;
                }
            },
            deep: true,
            immediate: true
        }
    },

    methods: {
        async onSaveEdits() {
            console.log(this.orderTypePayment);

            if (this.isCreditCardOrder && this.isDetailsTabActive) {
                this.createNotificationError({
                    message: this.$tc('Orders with payment type Credit Card cannot be edited.'),
                });
                return;
            }

            if (this.originalZipCode !== this.shippingZipCode) {
                this.createNotificationWarning({
                    message: this.$tc('Shipping ZIP code has been changed.'),
                });

                await this.setZipCodeChangedFlag();
            }

            this.$super('onSaveEdits');
        },

        async setZipCodeChangedFlag() {
            try {
                console.log('Full Order Object:', this.order);
                console.log('Current Custom Fields:', this.order.customFields);

                if (!this.order.customFields) {
                    this.order.customFields = {};
                }
                this.order.customFields.zipCodeChanged = true;

                console.log('Updated Custom Fields:', this.order.customFields);

                await this.orderRepository.save([{
                    id: this.order.id,
                    customFields: this.order.customFields
                }], Shopware.Context.api);

                this.createNotificationSuccess({
                    message: this.$tc('ZIP code change has been recorded.')
                });

            } catch (error) {
                console.log(error)
            }
        }
    }
});
