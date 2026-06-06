export function getSanitizedName(rawName) {
    if (!rawName) return 'tidak-diketahui';
    const safe = rawName.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
    return safe || 'tidak-diketahui';
}

export function getDownloadTimestamp() {
    const now = new Date();
    const pad = (num) => String(num).padStart(2, '0');
    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
}
