import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ExplorerHeaderComponent extends Component {
    @service explorerState;
    @tracked state = [];

    constructor(owner, { pod }) {
        super(...arguments);
        this.state = this.explorerState.get(pod);
        this.explorerState.on('change', (id, state) => {
            if (id === pod) {
                this.state = state;
            }
        });
    }

    @action onStateClicked(content) {
        if (typeof this.args.onStateClicked === 'function') {
            this.args.onStateClicked(content);
        }
    }
}
