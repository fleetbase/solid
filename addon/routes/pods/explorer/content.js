import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PodsExplorerContentRoute extends Route {
    @service fetch;

    model({ slug }) {
        return this.fetch.get('pods', { slug }, { namespace: 'solid/int/v1' });
    }
}
