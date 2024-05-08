import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PodsExplorerRoute extends Route {
    @service fetch;
    @service explorerState;

    queryParmas = {
        query: {
            refreshModel: true,
        },
        pod: {
            refreshModel: false,
        },
        cursor: {
            refreshModel: false,
        },
    };

    @action willTransition(transition) {
        const pod = transition.to.queryParams.pod;
        const cursor = transition.to.queryParams.cursor;
        if (pod && cursor) {
            this.explorerState.trackWithCursor(pod, cursor);
        }
    }

    beforeModel(transition) {
        const pod = transition.to.queryParams.pod;
        const cursor = transition.to.queryParams.cursor;
        if (pod && cursor) {
            this.explorerState.trackWithCursor(pod, cursor);
        }
    }

    model({ id, query }) {
        return this.fetch.get('pods', { id, query }, { namespace: 'solid/int/v1' });
    }

    afterModel(model, transition) {
        this.explorerState.track(transition.to.queryParams.pod, model);
    }
}
