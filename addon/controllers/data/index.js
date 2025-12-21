import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';

export default class DataIndexController extends Controller {
    @service hostRouter;
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service crud;
    @tracked query = '';
    queryParams = ['query'];
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
                        fn: this.deleteItem,
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
        this.hostRouter.transitionTo('console.solid-protocol.home');
    }

    @action viewContents(content) {
        if (content.type === 'folder') {
            return this.hostRouter.transitionTo('console.solid-protocol.data.content', content.slug);
        }

        if (content.type === 'file') {
            // Open file viewer or download
            window.open(content.url, '_blank');
        }
    }

    @action createFolder() {
        this.modalsManager.show('modals/create-solid-folder', {
            title: 'Create New Folder',
            acceptButtonText: 'Create',
            acceptButtonIcon: 'folder-plus',
            folderName: '',
            confirm: async (modal) => {
                const folderName = modal.getOption('folderName');
                
                if (!folderName) {
                    return this.notifications.warning('Please enter a folder name.');
                }

                try {
                    const response = await this.fetch.post('data/folder', {
                        name: folderName
                    }, { 
                        namespace: 'solid/int/v1' 
                    });

                    if (response.success) {
                        this.notifications.success(`Folder "${folderName}" created successfully!`);
                        this.hostRouter.refresh();
                        return modal.done();
                    }
                    
                    this.notifications.error(response.error || 'Failed to create folder.');
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action importResources() {
        const resourceTypes = {
            vehicles: false,
            drivers: false,
            contacts: false,
            orders: false,
        };

        this.modalsManager.show('modals/import-solid-resources', {
            title: 'Import Fleetops Resources',
            acceptButtonText: 'Import Selected',
            acceptButtonIcon: 'download',
            resourceTypes,
            importProgress: null,
            toggleResourceType: (type) => {
                resourceTypes[type] = !resourceTypes[type];
            },
            confirm: async (modal) => {
                const selected = Object.keys(resourceTypes).filter(type => resourceTypes[type]);
                
                if (selected.length === 0) {
                    return this.notifications.warning('Please select at least one resource type to import.');
                }

                try {
                    modal.setOption('importProgress', `Importing ${selected.join(', ')}...`);
                    
                    const response = await this.fetch.post('data/import', {
                        resource_types: selected
                    }, { 
                        namespace: 'solid/int/v1' 
                    });

                    if (response.success) {
                        this.notifications.success(`Successfully imported ${response.imported_count} resources!`);
                        this.hostRouter.refresh();
                        return modal.done();
                    }
                    
                    this.notifications.error(response.error || 'Failed to import resources.');
                    modal.setOption('importProgress', null);
                } catch (error) {
                    modal.setOption('importProgress', null);
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action deleteItem(item) {
        this.modalsManager.confirm({
            title: `Are you sure you want to delete this ${item.type}?`,
            body: `Deleting "${item.name}" will permanently remove it from your storage. This is irreversible!`,
            acceptButtonText: 'Delete Forever',
            confirm: async () => {
                try {
                    await this.fetch.delete(`data/${item.type}/${item.slug}`, {}, { namespace: 'solid/int/v1' });
                    this.notifications.success(`${item.type === 'folder' ? 'Folder' : 'File'} deleted successfully!`);
                    this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
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

    @task({ restartable: true }) *search(event) {
        yield timeout(300);
        const query = typeof event.target.value === 'string' ? event.target.value : '';
        this.hostRouter.transitionTo('console.solid-protocol.data.index', { queryParams: { query } });
    }
}
