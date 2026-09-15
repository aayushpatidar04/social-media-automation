import { ref } from 'vue'

const listeners = ref([])

export const toast = {
    success(message, duration = 4000) {
        this._emit({ type: 'success', message, duration })
    },
    error(message, duration = 5000) {
        this._emit({ type: 'error', message, duration })
    },
    info(message, duration = 4000) {
        this._emit({ type: 'info', message, duration })
    },
    _emit(toastData) {
        listeners.value.forEach((fn) => fn(toastData))
    },
    onToast(fn) {
        listeners.value.push(fn)
    },
    offToast(fn) {
        listeners.value = listeners.value.filter((l) => l !== fn)
    },
}

export function useToast() {
    return toast
}
