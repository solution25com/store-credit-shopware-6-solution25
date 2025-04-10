import { Component } from 'Shopware';

Component.override('sw-order-address-selection', {
    computed: {
        addressOptions() {
            const addresses = (this.customer?.addresses || []).map(item => {
                return {
                    label: `${item.street}, ${item.zipcode} ${item.city}, ${item.country?.translated?.name ?? ''}`,
                    ...item,
                };
            });

            if (this.address) {
                addresses.unshift({
                    label: this.address?.zipcode
                        ? `${this.address.street}, ${this.address.zipcode} ${this.address.city}, ${this.address?.country?.translated?.name ?? ''}`
                        : `${this.address.street}, ${this.address.city}, ${this.address?.country?.translated?.name ?? ''}`,
                    ...this.address,
                });
            }

            return addresses;
        }
    }
});