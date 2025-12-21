import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DataContentRoute extends Route {
    @service fetch;

    model({ slug }) {
        // Fetch folder/container contents within the user's pod
        return this.fetch.get('data/folder', { slug }, { namespace: 'solid/int/v1' });
    }
}
