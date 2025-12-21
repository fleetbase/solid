import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class PodsIndexController extends Controller {
    @service hostRouter;
    @service notifications;
    @service filters;
    @service fetch;
    @service modalsManager;
    @service crud;
    @tracked query = '';

    columns = [
        {
            label: 'Pod',
            valuePath: 'name',
            width: '60%',
            cellComponent: 'table/cell/anchor',
            onClick: this.explorePod,
        },
        {
            label: 'Size',
            valuePath: 'size',
            width: '5%',
        },
        {
            label: 'Type',
            valuePath: 'type',
            width: '10%',
        },
        {
            label: 'Last Modified',
            valuePath: 'last_modified',
            width: '15%',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Pod Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: 'Browse',
                    fn: this.openPod,
                },
                {
                    label: 'Backup',
                    fn: this.backupPod,
                },
                {
                    label: 'Re-sync',
                    fn: this.resyncPod,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete',
                    fn: this.deletePod,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    @action reload() {
        this.hostRouter.refresh();
    }

    @action openPod(pod) {
        this.hostRouter.transitionTo('console.solid-protocol.pods.index.pod', pod);
    }

    @action explorePod(pod) {
        this.hostRouter.transitionTo('console.solid-protocol.pods.explorer', pod, { queryParams: { cursor: pod.id, pod: pod.id } });
    }

    @action createPod() {
        this.modalsManager.show('modals/create-pod', {
            title: 'Create a new Pod',
            acceptButtonText: 'Create Pod',
            pod: {
                name: '',
                description: '',
            },
            requestPodCreation: this.requestPodCreation,
            confirm: async (modal) => {
                const pod = this.modalsManager.getOption('pod');

                if (!pod.name.trim()) {
                    return this.notifications.error('Pod name cannot be empty!');
                }

                try {
                    const response = await this.requestPodCreation.perform(pod);
                    if (response.success) {
                        this.notifications.success(`Pod "${pod.name}" created successfully!`);
                        // Refresh the pod list
                        this.hostRouter.refresh();
                        return modal.done();
                    }
                    
                    this.notifications.error(response.error || 'Failed to create pod.');
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action backupPod() {
        this.modalsManager.confirm({
            title: 'Are you sure you want to create a backup?',
            body: 'Running a backup will create a duplicate Pod with the same contents.',
            acceptButtonText: 'Start Backup',
            confirm: () => {},
        });
    }

    @action resyncPod() {
        this.modalsManager.confirm({
            title: 'Are you sure you want to re-sync?',
            body: 'Running a re-sync will update all data from Fleetbase to this pod, overwriting the current contents with the latest.',
            acceptButtonText: 'Start Sync',
            confirm: () => {},
        });
    }

    @action deletePod() {
        this.modalsManager.confirm({
            title: 'Are you sure you want to delete this Pod?',
            body: "Deleting this Pod will destroy this pod and all it's contents. This is irreversible!",
            acceptButtonText: 'Delete Forever',
            confirm: () => {},
        });
    }

    @action deleteSelectedPods() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: 'name',
            acceptButtonText: 'Delete All',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    @task({ restartable: true }) *search(event) {
        yield timeout(300);
        this.query = typeof event.target.value === 'string' ? event.target.value : '';
    }

    @task *requestPodCreation(pod) {
        const response = yield this.fetch.post('pods', {
            name: pod.name.trim(),
            description: pod.description.trim() || null
        }, { 
            namespace: 'solid/int/v1' 
        });

        return response;
    }
}
