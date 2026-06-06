export function isSeninKamisJS(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    const day = d.getDay(); // 1 for Monday, 4 for Thursday
    return (day === 1 || day === 4);
}
