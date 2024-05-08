import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class PodsExplorerController extends Controller {
    @service hostRouter;
    @service fetch;
    @service notifications;
    @service explorerState;
    @service modalsManager;
    @service crud;
    @tracked cursor = '';
    @tracked pod = '';
    @tracked query = '';
    queryParams = ['cursor', 'pod', 'query'];
    columns = [
        {
            label: 'Name',
            valuePath: 'name',
            width: '75%',
            cellComponent: 'table/cell/pod-content-name',
            onClick: this.viewContents,
        },
        {
            label: 'Type',
            valuePath: 'type',
            cellClassNames: 'capitalize',
            width: '5%',
        },
        {
            label: 'Size',
            valuePath: 'size',
            width: '5%',
        },
        {
            label: 'Created At',
            valuePath: 'created_at',
            width: '15%',
        },
        {
            label: '',
            cellComponent: 'table/cell/pod-content-actions',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: (content) => {
                return [
                    {
                        label: content.type === 'folder' ? 'Browse Folder' : 'View Contents',
                        fn: this.viewContents,
                    },
                    {
                        separator: true,
                    },
                    {
                        label: 'Delete',
                        fn: this.deleteSomething,
                    },
                ];
            },
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    @action reload() {
        this.hostRouter.refresh();
    }

    @action back() {
        if (typeof this.cursor === 'string' && this.cursor.length && this.cursor !== this.model.id) {
            const current = this.reverseCursor();
            return this.hostRouter.transitionTo('console.solid-protocol.pods.explorer', current, { queryParams: { cursor: this.cursor, pod: this.pod } });
        }

        this.hostRouter.transitionTo('console.solid-protocol.pods.index');
    }

    @action viewContents(content) {
        if (content.type === 'folder') {
            return this.hostRouter.transitionTo('console.solid-protocol.pods.explorer', content, { queryParams: { cursor: this.trackCursor(content), pod: this.pod } });
        }

        if (content.type === 'file') {
            return this.hostRouter.transitionTo('console.solid-protocol.pods.explorer.content', content);
        }

        return this.hostRouter.transitionTo('console.solid-protocol.pods.explorer', this.pod, { queryParams: { cursor: this.trackCursor(content), pod: this.pod } });
    }

    @action deleteSomething() {
        this.modalsManager.confirm({
            title: 'Are you sure you want to delete this content?',
            body: 'Deleting this Content will remove this content from this pod. This is irreversible!',
            acceptButtonText: 'Delete Forever',
            confirm: () => {},
        });
    }

    @action deleteSelected() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: 'name',
            acceptButtonText: 'Delete All',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    trackCursor(content) {
        if (typeof this.cursor === 'string' && this.cursor.includes(content.id)) {
            const segments = this.cursor.split(':');
            const currentIndex = segments.findIndex((segment) => segment === content.id);

            if (currentIndex > -1) {
                const retainedSegments = segments.slice(0, currentIndex + 1);
                this.cursor = retainedSegments.join(':');
                return this.cursor;
            }
        }

        this.cursor = this.cursor ? `${this.cursor}:${content.id}` : content.id;
        return this.cursor;
    }

    reverseCursor() {
        const segments = this.cursor.split(':');
        segments.pop();
        const current = segments[segments.length - 1];
        this.cursor = segments.join(':');
        return current;
    }

    @task({ restartable: true }) *search(event) {
        yield timeout(300);
        const query = typeof event.target.value === 'string' ? event.target.value : '';
        this.hostRouter.transitionTo('console.solid-protocol.pods.explorer', this.model.id, { queryParams: { cursor: this.cursor, query } });
    }
}
