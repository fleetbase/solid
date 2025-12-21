import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class DataContentController extends Controller {
    @service hostRouter;

    @action back() {
        this.hostRouter.transitionTo('console.solid-protocol.data.index');
    }

    @action viewFile() {
        if (this.model.url) {
            window.open(this.model.url, '_blank');
        }
    }
}
