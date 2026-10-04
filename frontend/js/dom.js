/**
 * Создаёт DOM-элемент. Строковые дети становятся текстовыми узлами, а не HTML:
 * данные пользователей (имя, интересы) никогда не интерпретируются как разметка.
 */
export function el(tag, attributes = {}, ...children) {
    const node = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) {
        if (value === false || value === null || value === undefined) {
            continue;
        }

        if (name === 'class') {
            node.className = value;
        } else if (name.startsWith('on')) {
            node.addEventListener(name.slice(2), value);
        } else {
            node.setAttribute(name, value === true ? '' : String(value));
        }
    }

    for (const child of children.flat()) {
        if (child !== null && child !== undefined && child !== false) {
            node.append(child);
        }
    }

    return node;
}
