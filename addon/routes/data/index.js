import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DataIndexRoute extends Route {
    @service fetch;

    queryParams = {
        query: {
            refreshModel: true,
        },
    };

    model({ query }) {
        // Fetch the user's primary pod data
        return this.fetch.get('data', { query }, { namespace: 'solid/int/v1' });
    }
}
