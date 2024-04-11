import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class AdminSolidServerConfigComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked configLoaded = false;
    @tracked host;
    @tracked port;
    @tracked secure;

    constructor() {
        super(...arguments);
        this.loadServerConfig.perform();
    }

    getConfig() {
        return {
            host: this.host,
            port: this.port,
            secure: this.secure,
        };
    }

    setConfig(config) {
        this.host = config.host;
        this.port = config.port;
        this.secure = config.secure;
    }

    @task *loadServerConfig() {
        const config = yield this.fetch.get('server-config', {}, { namespace: 'solid/int/v1' });
        if (config) {
            this.setConfig(config);
            this.configLoaded = true;
        }
    }

    @task *saveServerConfig() {
        try {
            const config = yield this.fetch.post('server-config', { server: this.getConfig() }, { namespace: 'solid/int/v1' });
            if (config) {
                this.setConfig(config);
                this.notifications.success('Solid server config udpated successfully.');
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
