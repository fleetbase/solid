# Refactoring Verification Checklist

## Code Cleanup ✓
- [x] Removed old `addon/routes/pods/` directory
- [x] Removed old `addon/controllers/pods/` directory  
- [x] Removed old `addon/templates/pods/` directory
- [x] Removed `server/src/Http/Controllers/PodController.php`
- [x] Removed `server/src/Http/Controllers/ContainerController.php`
- [x] Removed commented code from `addon/templates/application.hbs`
- [x] Removed commented routes from `server/src/routes.php`

## New Files Created ✓
- [x] `addon/routes/data/index.js`
- [x] `addon/routes/data/content.js`
- [x] `addon/controllers/data/index.js`
- [x] `addon/controllers/data/content.js`
- [x] `addon/templates/data/index.hbs`
- [x] `addon/templates/data/content.hbs`
- [x] `server/src/Http/Controllers/DataController.php`

## Routes Updated ✓
- [x] `addon/routes.js` - Simplified to data routes
- [x] `server/src/routes.php` - Updated API endpoints

## Templates Updated ✓
- [x] `addon/templates/application.hbs` - Sidebar navigation
- [x] `addon/templates/home.hbs` - Quick actions and terminology

## Controllers Updated ✓
- [x] `addon/controllers/home.js` - Navigation method

## Services Updated ✓
- [x] `server/src/Services/PodService.php` - Added createFolder() and deleteResource()

## Terminology Changes ✓
- [x] "Pods" → "Data" throughout UI
- [x] "Container" → "Folder" in code comments and user-facing text
- [x] Updated all user-facing messages

## Testing Required
- [ ] OIDC authentication flow
- [ ] Pod URL extraction from WebID
- [ ] Data browser loads correctly
- [ ] Folder creation works
- [ ] Folder navigation works
- [ ] File/folder deletion works
- [ ] Resource import (vehicles, drivers, contacts, orders)
- [ ] Search functionality
- [ ] Error handling (401, invalid inputs)

## API Endpoints to Test

### GET /solid/int/v1/data
Expected: Returns root contents of user's pod

### GET /solid/int/v1/data/folder/{slug}
Expected: Returns contents of specified folder

### POST /solid/int/v1/data/folder
Payload: `{ "name": "test-folder", "path": "" }`
Expected: Creates new folder

### DELETE /solid/int/v1/data/{type}/{slug}
Example: `DELETE /solid/int/v1/data/folder/vehicles`
Expected: Deletes specified folder or file

### POST /solid/int/v1/data/import
Payload: `{ "resource_types": ["vehicles", "drivers"] }`
Expected: Imports selected resources into folders

## Files Count Summary
- **Created:** 7 files
- **Modified:** 6 files
- **Deleted:** 14 files (12 frontend + 2 backend)
- **Net change:** -1 files (cleaner codebase!)

## Code Quality Checks
- [x] No references to old `pods.explorer` or `pods.index` routes
- [x] No references to `PodController` or `ContainerController`
- [x] All imports and dependencies updated
- [x] Consistent naming conventions (data/folder)
- [x] Clean, readable code structure
