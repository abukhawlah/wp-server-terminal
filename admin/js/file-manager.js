/* global wstFileManager, CodeMirror */
/* WP Server Terminal - File Manager */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------
    var currentPath = wstFileManager.initial_path;
    var editor = null;
    var currentEditPath = null;
    var originalContent = null;

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        loadDirectory(currentPath);
        setupUpload();
        setupDragDrop();
        setupButtons();
        setupEditorShortcuts();
    }

    // -------------------------------------------------------------------------
    // Directory loading
    // -------------------------------------------------------------------------
    function loadDirectory(path) {
        var tbody = document.querySelector('#wst-file-list tbody');
        if (!tbody) return;

        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;">Loading...</td></tr>';

        ajax('wst_file_list', { path: path }, function (success, data, errorMsg) {
            if (success) {
                renderFileList(data);
            } else {
                tbody.innerHTML = '<tr><td colspan="5" style="color:#c00;padding:16px;">Error: ' + escapeHtml(errorMsg) + '</td></tr>';
            }
        });
    }

    // -------------------------------------------------------------------------
    // File list rendering
    // -------------------------------------------------------------------------
    function renderFileList(data) {
        var tbody = document.querySelector('#wst-file-list tbody');
        if (!tbody) return;

        currentPath = data.path;
        renderBreadcrumb(data.path);
        tbody.innerHTML = '';

        if (!data.entries || data.entries.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#888;">Empty directory</td></tr>';
            return;
        }

        data.entries.forEach(function (entry) {
            var tr = document.createElement('tr');
            var isDir = entry.type === 'dir';
            var icon = isDir ? '📁' : '📄';
            var fullPath = entry.full_path || (data.path.replace(/\/$/, '') + '/' + entry.name);

            // Icon + Name cell
            var tdName = document.createElement('td');
            var nameLink = document.createElement('span');
            nameLink.style.cursor = 'pointer';
            nameLink.style.color = '#0073aa';
            nameLink.textContent = icon + ' ' + entry.name;
            if (isDir) {
                nameLink.addEventListener('click', function () {
                    loadDirectory(fullPath);
                });
            } else {
                nameLink.addEventListener('click', function () {
                    openEditor(data.path.replace(/\/$/, '') + '/' + entry.name);
                });
            }
            tdName.appendChild(nameLink);

            // Size cell
            var tdSize = document.createElement('td');
            tdSize.textContent = isDir ? '—' : formatBytes(entry.size);

            // Modified cell
            var tdModified = document.createElement('td');
            tdModified.textContent = formatDate(entry.modified);

            // Permissions cell
            var tdPerms = document.createElement('td');
            tdPerms.textContent = entry.permissions || '';

            // Actions cell
            var tdActions = document.createElement('td');

            if (!isDir) {
                // Edit button
                var editBtn = document.createElement('button');
                editBtn.textContent = 'Edit';
                editBtn.className = 'button button-small';
                editBtn.style.marginRight = '4px';
                (function (fp) {
                    editBtn.addEventListener('click', function () {
                        openEditor(fp);
                    });
                }(data.path.replace(/\/$/, '') + '/' + entry.name));
                tdActions.appendChild(editBtn);

                // Download button
                var dlBtn = document.createElement('button');
                dlBtn.textContent = 'Download';
                dlBtn.className = 'button button-small';
                dlBtn.style.marginRight = '4px';
                (function (fp) {
                    dlBtn.addEventListener('click', function () {
                        downloadFile(fp);
                    });
                }(fullPath));
                tdActions.appendChild(dlBtn);
            }

            // Delete button
            var delBtn = document.createElement('button');
            delBtn.textContent = 'Delete';
            delBtn.className = 'button button-small';
            delBtn.style.marginRight = '4px';
            if (entry.protected) {
                delBtn.disabled = true;
                delBtn.title = 'This file is protected';
            } else {
                (function (fp, prot) {
                    delBtn.addEventListener('click', function () {
                        deleteFile(fp, prot);
                    });
                }(fullPath, !!entry.protected));
            }
            tdActions.appendChild(delBtn);

            // Rename button
            var renameBtn = document.createElement('button');
            renameBtn.textContent = 'Rename';
            renameBtn.className = 'button button-small';
            (function (fp, oldName) {
                renameBtn.addEventListener('click', function () {
                    renameItem(fp, oldName);
                });
            }(fullPath, entry.name));
            tdActions.appendChild(renameBtn);

            tr.appendChild(tdName);
            tr.appendChild(tdSize);
            tr.appendChild(tdModified);
            tr.appendChild(tdPerms);
            tr.appendChild(tdActions);
            tbody.appendChild(tr);
        });
    }

    // -------------------------------------------------------------------------
    // Breadcrumb
    // -------------------------------------------------------------------------
    function renderBreadcrumb(path) {
        var container = document.getElementById('wst-breadcrumb');
        if (!container) return;

        container.innerHTML = '';

        var parts = path.replace(/\/$/, '').split('/');
        // parts[0] will be '' for absolute paths — skip it
        var segments = [];
        var cumulative = '';

        parts.forEach(function (part) {
            if (part === '') {
                cumulative = '/';
                segments.push({ label: 'Home', path: '/' });
            } else {
                cumulative = cumulative.replace(/\/$/, '') + '/' + part;
                segments.push({ label: part, path: cumulative });
            }
        });

        segments.forEach(function (seg, idx) {
            if (idx > 0) {
                var sep = document.createElement('span');
                sep.textContent = ' / ';
                sep.style.color = '#888';
                container.appendChild(sep);
            }

            if (idx === segments.length - 1) {
                // Last segment — not clickable
                var span = document.createElement('span');
                span.textContent = seg.label;
                span.style.fontWeight = 'bold';
                container.appendChild(span);
            } else {
                var link = document.createElement('span');
                link.textContent = seg.label;
                link.style.cursor = 'pointer';
                link.style.color = '#0073aa';
                (function (p) {
                    link.addEventListener('click', function () {
                        loadDirectory(p);
                    });
                }(seg.path));
                container.appendChild(link);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Editor
    // -------------------------------------------------------------------------
    function openEditor(path) {
        ajax('wst_file_read', { path: path }, function (success, data, errorMsg) {
            if (!success) {
                alert('Could not open file: ' + errorMsg);
                return;
            }

            if (data.is_binary) {
                alert('This is a binary file and cannot be edited.');
                return;
            }

            var content = data.content || '';
            var language = data.language || 'text';

            var panel = document.getElementById('wst-editor-panel');
            if (panel) panel.style.display = 'block';

            var filenameEl = document.getElementById('wst-editor-filename');
            if (filenameEl) filenameEl.textContent = path;

            var container = document.getElementById('wst-codemirror-container');
            if (!container) return;

            if (!editor) {
                editor = CodeMirror(container, {
                    value: content,
                    mode: language,
                    lineNumbers: true,
                    theme: 'default',
                    lineWrapping: false,
                    tabSize: 4,
                    indentWithTabs: true
                });
            } else {
                editor.setValue(content);
                editor.setOption('mode', language);
            }

            currentEditPath = path;
            originalContent = content;

            // Refresh CodeMirror so it renders correctly inside newly shown panel
            setTimeout(function () {
                editor.refresh();
            }, 50);
        });
    }

    function saveFile() {
        if (!currentEditPath) return;

        var content = editor.getValue();

        ajax('wst_file_write', { path: currentEditPath, content: content }, function (success, data, errorMsg) {
            if (success) {
                originalContent = content;
                showSavedMessage();
            } else {
                alert('Save failed: ' + errorMsg);
            }
        });
    }

    function showSavedMessage() {
        var toolbar = document.getElementById('wst-editor-toolbar');
        if (!toolbar) return;

        var msg = document.getElementById('wst-saved-msg');
        if (!msg) {
            msg = document.createElement('span');
            msg.id = 'wst-saved-msg';
            msg.style.marginLeft = '12px';
            msg.style.color = '#46b450';
            msg.style.fontWeight = 'bold';
            toolbar.appendChild(msg);
        }
        msg.textContent = 'Saved!';
        msg.style.display = 'inline';

        clearTimeout(msg._hideTimer);
        msg._hideTimer = setTimeout(function () {
            msg.style.display = 'none';
        }, 2500);
    }

    function closeEditor() {
        if (editor && editor.getValue() !== originalContent) {
            if (!confirm('You have unsaved changes. Close anyway?')) {
                return;
            }
        }

        var panel = document.getElementById('wst-editor-panel');
        if (panel) panel.style.display = 'none';

        currentEditPath = null;
    }

    // -------------------------------------------------------------------------
    // Delete / Rename
    // -------------------------------------------------------------------------
    function deleteFile(path, isProtected) {
        if (isProtected) {
            alert('This file is protected and cannot be deleted.');
            return;
        }

        if (!confirm('Delete ' + path + '? It will be moved to trash.')) {
            return;
        }

        ajax('wst_file_delete', { path: path }, function (success, data, errorMsg) {
            if (success) {
                loadDirectory(currentPath);
            } else {
                alert('Delete failed: ' + errorMsg);
            }
        });
    }

    function renameItem(path, oldName) {
        var newName = prompt('Rename "' + oldName + '" to:', oldName);
        if (!newName || newName === oldName) return;

        var dir = path.substring(0, path.lastIndexOf('/'));
        var newPath = dir + '/' + newName;

        ajax('wst_file_rename', { old_path: path, new_path: newPath }, function (success, data, errorMsg) {
            if (success) {
                loadDirectory(currentPath);
            } else {
                alert('Rename failed: ' + errorMsg);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Download
    // -------------------------------------------------------------------------
    function downloadFile(path) {
        var url = wstFileManager.ajaxurl +
            '?action=wst_file_download' +
            '&nonce=' + encodeURIComponent(wstFileManager.nonce) +
            '&path=' + encodeURIComponent(path);
        window.location.href = url;
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------
    function setupUpload() {
        var uploadBtn = document.getElementById('wst-upload-btn');
        var uploadInput = document.getElementById('wst-upload-input');

        if (uploadBtn && uploadInput) {
            uploadBtn.addEventListener('click', function () {
                uploadInput.click();
            });

            uploadInput.addEventListener('change', function () {
                var files = uploadInput.files;
                for (var i = 0; i < files.length; i++) {
                    uploadFile(files[i]);
                }
                uploadInput.value = '';
            });
        }
    }

    function uploadFile(file) {
        var formData = new FormData();
        formData.append('action', 'wst_file_upload');
        formData.append('nonce', wstFileManager.nonce);
        formData.append('target_dir', currentPath);
        formData.append('wst_file', file);

        var xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                showUploadProgress(file.name, pct);
            }
        });

        xhr.addEventListener('load', function () {
            hideUploadProgress();
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (ex) {
                alert('Upload failed: invalid server response.');
                return;
            }
            if (response.success) {
                loadDirectory(currentPath);
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : 'Unknown error';
                alert('Upload failed: ' + msg);
            }
        });

        xhr.addEventListener('error', function () {
            hideUploadProgress();
            alert('Upload failed: network error.');
        });

        xhr.open('POST', wstFileManager.ajaxurl, true);
        xhr.send(formData);
    }

    function showUploadProgress(filename, pct) {
        var el = document.getElementById('wst-upload-progress');
        if (el) {
            el.textContent = 'Uploading ' + escapeHtml(filename) + ': ' + pct + '%';
            el.style.display = 'block';
        }
    }

    function hideUploadProgress() {
        var el = document.getElementById('wst-upload-progress');
        if (el) el.style.display = 'none';
    }

    // -------------------------------------------------------------------------
    // Drag & Drop
    // -------------------------------------------------------------------------
    function setupDragDrop() {
        var dropZone = document.getElementById('wst-drop-zone');

        // Page-level handlers so dragging anywhere on the page triggers the zone
        document.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (dropZone) dropZone.classList.add('dragover');
        });

        document.addEventListener('dragleave', function (e) {
            // Only remove class when leaving the document entirely
            if (e.relatedTarget === null) {
                if (dropZone) dropZone.classList.remove('dragover');
            }
        });

        document.addEventListener('drop', function (e) {
            e.preventDefault();
            if (dropZone) dropZone.classList.remove('dragover');
            var files = e.dataTransfer.files;
            for (var i = 0; i < files.length; i++) {
                uploadFile(files[i]);
            }
        });

        if (dropZone) {
            dropZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });

            dropZone.addEventListener('dragleave', function () {
                dropZone.classList.remove('dragover');
            });

            dropZone.addEventListener('drop', function (e) {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                var files = e.dataTransfer.files;
                for (var i = 0; i < files.length; i++) {
                    uploadFile(files[i]);
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Toolbar buttons
    // -------------------------------------------------------------------------
    function setupButtons() {
        var newFolderBtn = document.getElementById('wst-new-folder-btn');
        if (newFolderBtn) {
            newFolderBtn.addEventListener('click', function () {
                var name = prompt('Folder name:');
                if (name) createItem('folder', name);
            });
        }

        var newFileBtn = document.getElementById('wst-new-file-btn');
        if (newFileBtn) {
            newFileBtn.addEventListener('click', function () {
                var name = prompt('File name:');
                if (name) createItem('file', name);
            });
        }

        var saveBtn = document.getElementById('wst-editor-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveFile);
        }

        var closeBtn = document.getElementById('wst-editor-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeEditor);
        }
    }

    // -------------------------------------------------------------------------
    // Create item (folder or file)
    // -------------------------------------------------------------------------
    function createItem(type, name) {
        var fullPath = currentPath.replace(/\/$/, '') + '/' + name;

        if (type === 'folder') {
            ajax('wst_file_mkdir', { path: fullPath }, function (success, data, errorMsg) {
                if (success) {
                    loadDirectory(currentPath);
                } else {
                    alert('Could not create folder: ' + errorMsg);
                }
            });
        } else {
            ajax('wst_file_write', { path: fullPath, content: '' }, function (success, data, errorMsg) {
                if (success) {
                    loadDirectory(currentPath);
                } else {
                    alert('Could not create file: ' + errorMsg);
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Editor keyboard shortcuts
    // -------------------------------------------------------------------------
    function setupEditorShortcuts() {
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's' && currentEditPath) {
                e.preventDefault();
                saveFile();
            }
        });
    }

    // -------------------------------------------------------------------------
    // AJAX helper
    // -------------------------------------------------------------------------
    /**
     * ajax(action, data, callback)
     * callback(success: bool, responseData: *, errorMessage: string)
     */
    function ajax(action, data, callback) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', wstFileManager.nonce);

        Object.keys(data).forEach(function (key) {
            formData.append(key, data[key]);
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', wstFileManager.ajaxurl, true);

        xhr.addEventListener('load', function () {
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (ex) {
                callback(false, null, 'Invalid server response.');
                return;
            }

            if (response.success) {
                callback(true, response.data, null);
            } else {
                var msg = 'Unknown error';
                if (response.data) {
                    if (typeof response.data === 'string') {
                        msg = response.data;
                    } else if (response.data.message) {
                        msg = response.data.message;
                    }
                }
                callback(false, null, msg);
            }
        });

        xhr.addEventListener('error', function () {
            callback(false, null, 'Network error. Please check your connection.');
        });

        xhr.send(formData);
    }

    // -------------------------------------------------------------------------
    // Utility helpers
    // -------------------------------------------------------------------------
    function formatBytes(bytes) {
        bytes = parseInt(bytes, 10) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function formatDate(timestamp) {
        var d = new Date(timestamp * 1000);
        var yyyy = d.getFullYear();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        var hh = String(d.getHours()).padStart(2, '0');
        var min = String(d.getMinutes()).padStart(2, '0');
        return yyyy + '-' + mm + '-' + dd + ' ' + hh + ':' + min;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}());
