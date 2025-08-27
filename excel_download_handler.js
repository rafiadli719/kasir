// Excel Download Handler untuk Detail Omset
class ExcelDownloadHandler {
    constructor() {
        this.init();
    }

    init() {
        // Bind event listeners
        this.bindDownloadButton();
        this.bindFormValidation();
    }

    bindDownloadButton() {
        const downloadBtn = document.querySelector('a[href*="export_excel_omset.php"]');
        if (!downloadBtn) return;

        downloadBtn.addEventListener('click', (e) => {
            // Check if there's data to download
            const tableRows = document.querySelectorAll('.table tbody tr');
            if (tableRows.length === 0) {
                e.preventDefault();
                this.showAlert('Tidak ada data untuk diunduh. Silakan ubah filter pencarian.', 'warning');
                return false;
            }

            // Show confirmation dialog
            const confirmed = this.showConfirmation();
            if (!confirmed) {
                e.preventDefault();
                return false;
            }

            // Show loading state
            this.setDownloadLoading(downloadBtn, true);

            // Simulate download completion after a delay
            setTimeout(() => {
                this.setDownloadLoading(downloadBtn, false);
                this.showAlert('File Excel berhasil diunduh!', 'success');
            }, 2000);
        });
    }

    bindFormValidation() {
        const form = document.querySelector('form');
        if (!form) return;

        form.addEventListener('submit', (e) => {
            const tanggalAwal = document.getElementById('tanggal_awal').value;
            const tanggalAkhir = document.getElementById('tanggal_akhir').value;

            // Validate date range
            if (tanggalAwal && tanggalAkhir) {
                const startDate = new Date(tanggalAwal);
                const endDate = new Date(tanggalAkhir);

                if (startDate > endDate) {
                    e.preventDefault();
                    this.showAlert('Tanggal awal tidak boleh lebih besar dari tanggal akhir!', 'error');
                    return false;
                }

                // Check if date range is too large (more than 1 year)
                const diffTime = Math.abs(endDate - startDate);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                if (diffDays > 365) {
                    const proceed = confirm('Rentang tanggal lebih dari 1 tahun. Data yang diunduh mungkin sangat besar. Lanjutkan?');
                    if (!proceed) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
        });
    }

    showConfirmation() {
        const tableRows = document.querySelectorAll('.table tbody tr');
        const rowCount = tableRows.length;
        
        const message = `Anda akan mengunduh data omset sebanyak ${rowCount.toLocaleString()} transaksi dalam format Excel.\n\nFile akan berisi:\n• Data detail omset penjualan dan servis\n• Ringkasan statistik\n• Informasi filter yang diterapkan\n\nLanjutkan download?`;
        
        return confirm(message);
    }

    setDownloadLoading(button, loading) {
        if (loading) {
            button.style.pointerEvents = 'none';
            button.style.opacity = '0.7';
            
            const originalHTML = button.innerHTML;
            button.setAttribute('data-original-html', originalHTML);
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengunduh...';
        } else {
            button.style.pointerEvents = '';
            button.style.opacity = '';
            
            const originalHTML = button.getAttribute('data-original-html');
            if (originalHTML) {
                button.innerHTML = originalHTML;
                button.removeAttribute('data-original-html');
            }
        }
    }

    showAlert(message, type = 'info') {
        // Create alert element
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} excel-download-alert`;
        alertDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 8px;
            animation: slideInRight 0.3s ease-out;
        `;

        const iconMap = {
            'success': 'fas fa-check-circle',
            'error': 'fas fa-exclamation-circle',
            'warning': 'fas fa-exclamation-triangle',
            'info': 'fas fa-info-circle'
        };

        alertDiv.innerHTML = `
            <i class="${iconMap[type] || iconMap.info}"></i>
            <span>${message}</span>
            <button type="button" class="alert-close" style="background: none; border: none; color: inherit; font-size: 16px; cursor: pointer; margin-left: 10px;">&times;</button>
        `;

        // Add close functionality
        const closeBtn = alertDiv.querySelector('.alert-close');
        closeBtn.addEventListener('click', () => {
            this.removeAlert(alertDiv);
        });

        // Add to page
        document.body.appendChild(alertDiv);

        // Auto remove after 5 seconds
        setTimeout(() => {
            this.removeAlert(alertDiv);
        }, 5000);

        // Add CSS animation
        if (!document.querySelector('#excel-alert-styles')) {
            const styles = document.createElement('style');
            styles.id = 'excel-alert-styles';
            styles.textContent = `
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOutRight {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                .excel-download-alert {
                    transition: all 0.3s ease-out;
                }
            `;
            document.head.appendChild(styles);
        }
    }

    removeAlert(alertDiv) {
        if (alertDiv && alertDiv.parentNode) {
            alertDiv.style.animation = 'slideOutRight 0.3s ease-out';
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 300);
        }
    }

    // Utility method untuk format file size
    static formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Method untuk estimasi ukuran file
    estimateFileSize() {
        const tableRows = document.querySelectorAll('.table tbody tr');
        const rowCount = tableRows.length;
        
        // Rough estimate: ~200 bytes per row + headers and formatting
        const estimatedSize = (rowCount * 200) + 5000;
        return ExcelDownloadHandler.formatFileSize(estimatedSize);
    }
}

// Enhanced download button with more features
class EnhancedDownloadButton {
    constructor(buttonSelector) {
        this.button = document.querySelector(buttonSelector);
        this.init();
    }

    init() {
        if (!this.button) return;
        
        this.addTooltip();
        this.addFileInfo();
    }

    addTooltip() {
        const rowCount = document.querySelectorAll('.table tbody tr').length;
        const tooltip = `Download ${rowCount.toLocaleString()} transaksi dalam format Excel`;
        
        this.button.setAttribute('title', tooltip);
        this.button.setAttribute('data-toggle', 'tooltip');
    }

    addFileInfo() {
        const rowCount = document.querySelectorAll('.table tbody tr').length;
        const handler = new ExcelDownloadHandler();
        const estimatedSize = handler.estimateFileSize();
        
        // Add file info span if not exists
        if (!this.button.querySelector('.file-info')) {
            const fileInfoSpan = document.createElement('span');
            fileInfoSpan.className = 'file-info';
            fileInfoSpan.style.cssText = 'font-size: 11px; opacity: 0.8; margin-left: 5px;';
            fileInfoSpan.textContent = `(~${estimatedSize})`;
            this.button.appendChild(fileInfoSpan);
        }
    }
}

// Auto-initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize download handler
    new ExcelDownloadHandler();
    
    // Initialize enhanced download button
    new EnhancedDownloadButton('a[href*="export_excel_omset.php"]');
    
    // Add loading overlay functionality
    const downloadLink = document.querySelector('a[href*="export_excel_omset.php"]');
    if (downloadLink) {
        downloadLink.addEventListener('click', function() {
            // Create loading overlay
            const overlay = document.createElement('div');
            overlay.id = 'download-overlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 10000;
                color: white;
                font-size: 18px;
            `;
            overlay.innerHTML = `
                <div style="text-align: center; background: rgba(0,0,0,0.8); padding: 30px; border-radius: 10px;">
                    <i class="fas fa-download fa-3x fa-pulse" style="margin-bottom: 20px;"></i><br>
                    <strong>Memproses Download Excel...</strong><br>
                    <small>Mohon tunggu, file sedang dipersiapkan</small>
                </div>
            `;
            
            document.body.appendChild(overlay);
            
            // Remove overlay after 3 seconds
            setTimeout(() => {
                if (document.getElementById('download-overlay')) {
                    document.body.removeChild(overlay);
                }
            }, 3000);
        });
    }
});

// Export for use in other scripts
window.ExcelDownloadHandler = ExcelDownloadHandler;
window.EnhancedDownloadButton = EnhancedDownloadButton;