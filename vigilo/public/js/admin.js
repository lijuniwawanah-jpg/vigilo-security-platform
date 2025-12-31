document.addEventListener("DOMContentLoaded", () => {
    console.log("Admin dashboard loading...");
    loadStats();
    loadRecentLogs();
});

function loadStats() {
    console.log("Loading stats from API...");
    fetch("../api/admin/stats.php")
        .then(res => {
            console.log("Stats API response status:", res.status);
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            console.log("Stats API data:", data);
            if (data.success) {
                document.getElementById("stat_users").innerText = data.users || 0;
                document.getElementById("stat_docs").innerText = data.documents || 0;
                document.getElementById("stat_deleted").innerText = data.deleted || 0;
                document.getElementById("stat_links").innerText = data.links || 0;
                
                // Update sidebar badges
                const trashBadge = document.getElementById("trash-badge");
                const usersBadge = document.getElementById("users-badge");
                const linksBadge = document.getElementById("links-badge");
                const pendingBadge = document.getElementById("pending-badge");
                
                if (trashBadge) trashBadge.innerText = data.deleted || 0;
                if (usersBadge) usersBadge.innerText = data.users || 0;
                if (linksBadge) linksBadge.innerText = data.links || 0;
                if (pendingBadge) pendingBadge.innerText = data.pending || 0;
            } else {
                console.error("Failed to load stats:", data.error);
                setDefaultStats();
            }
        })
        .catch(error => {
            console.error("Error loading stats:", error);
            setDefaultStats();
        });
}

function setDefaultStats() {
    console.log("Setting default stats");
    document.getElementById("stat_users").innerText = "0";
    document.getElementById("stat_docs").innerText = "0";
    document.getElementById("stat_deleted").innerText = "0";
    document.getElementById("stat_links").innerText = "0";
    
    const badges = ['trash-badge', 'users-badge', 'links-badge', 'pending-badge'];
    badges.forEach(id => {
        const badge = document.getElementById(id);
        if (badge) badge.innerText = "0";
    });
}

function loadRecentLogs() {
    console.log("Loading recent logs from API...");
    const tbody = document.getElementById("recent_logs");
    
    fetch("../api/admin/recent_logs.php")
        .then(res => {
            console.log("Logs API response status:", res.status);
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            console.log("Logs API data:", data);
            if (data.success) {
                tbody.innerHTML = "";
                
                if (data.logs && data.logs.length > 0) {
                    data.logs.forEach(log => {
                        // Determine status badge class
                        let statusClass = 'status-review';
                        if (log.action.toLowerCase().includes('delete')) statusClass = 'status-rejected';
                        if (log.action.toLowerCase().includes('upload')) statusClass = 'status-pending';
                        if (log.action.toLowerCase().includes('verify') || log.action.toLowerCase().includes('approve')) statusClass = 'status-verified';
                        
                        // Get user initial
                        const userInitial = log.user ? log.user.charAt(0).toUpperCase() : '?';
                        
                        tbody.innerHTML += `
                            <tr>
                                <td>${log.user || 'System'}</td>
                                <td>
                                    <span class="status-badge ${statusClass}">
                                        ${log.action || 'Unknown Action'}
                                    </span>
                                </td>
                                <td>${log.details || 'No details'}</td>
                                <td>${log.time || 'Unknown'}</td>
                            </tr>
                        `;
                    });
                } else {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 2rem;">
                                No recent activity found
                            </td>
                        </tr>
                    `;
                }
            } else {
                console.error("Failed to load logs:", data.error);
                tbody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem; color: #ef476f;">
                            Failed to load activity logs
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error("Error loading logs:", error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" style="text-align: center; padding: 2rem; color: #ef476f;">
                        Error loading activity logs
                    </td>
                </tr>
            `;
        });
}