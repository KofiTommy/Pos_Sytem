async function refreshAdminNotifications() {
    const bellBadges = document.querySelectorAll('[data-notif-total]');
    const orderTargets = document.querySelectorAll('[data-pending-orders]');
    const messageTargets = document.querySelectorAll('[data-new-messages]');
    if (!bellBadges.length && !orderTargets.length && !messageTargets.length) return;

    try {
        const response = await fetch('../../php/admin-notifications.php');
        const data = await response.json();
        if (!data.success) return;

        const pendingOrders = Number((data.notifications || {}).pending_orders || 0);
        const newMessages = Number((data.notifications || {}).new_messages || 0);
        const total = Number((data.notifications || {}).total || 0);

        bellBadges.forEach((el) => {
            if (total > 0) {
                el.textContent = String(total);
                el.classList.remove('d-none');
            } else {
                el.textContent = '0';
                el.classList.add('d-none');
            }
        });

        orderTargets.forEach((el) => {
            el.textContent = String(pendingOrders);
        });

        messageTargets.forEach((el) => {
            el.textContent = String(newMessages);
        });
    } catch (error) {
        // Silent fail to avoid interrupting admin workflows.
    }
}

document.addEventListener('DOMContentLoaded', () => {
    refreshAdminNotifications();
    setInterval(refreshAdminNotifications, 60000);
});
