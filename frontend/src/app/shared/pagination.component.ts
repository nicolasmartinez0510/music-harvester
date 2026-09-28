import { Component, EventEmitter, Input, Output } from '@angular/core';

@Component({
  selector: 'app-pagination',
  template: `
    @if (total > 0) {
      <div class="pagination">
        @if (showPageSize) {
          <label class="pagination-size">
            <span>Por página</span>
            <select [value]="pageSize" (change)="onPageSizeChange($event)">
              @for (size of pageSizes; track size) {
                <option [value]="size">{{ size }}</option>
              }
            </select>
          </label>
        } @else {
          <span></span>
        }

        <div class="pagination-nav">
          <button type="button" class="btn btn-small" [disabled]="page <= 1" (click)="goTo(page - 1)">
            Anterior
          </button>
          <span class="pagination-info">{{ page }} / {{ totalPages }}</span>
          <button type="button" class="btn btn-small" [disabled]="page >= totalPages" (click)="goTo(page + 1)">
            Siguiente
          </button>
        </div>
      </div>
    }
  `,
})
export class PaginationComponent {
  readonly pageSizes = [10, 20, 30, 40, 50] as const;

  @Input({ required: true }) total = 0;
  @Input() page = 1;
  @Input() pageSize = 10;
  @Input() showPageSize = true;

  @Output() readonly pageChange = new EventEmitter<number>();
  @Output() readonly pageSizeChange = new EventEmitter<number>();

  get totalPages(): number {
    return Math.max(1, Math.ceil(this.total / this.pageSize));
  }

  goTo(page: number): void {
    const next = Math.min(this.totalPages, Math.max(1, page));
    if (next !== this.page) {
      this.pageChange.emit(next);
    }
  }

  onPageSizeChange(event: Event): void {
    const value = Number((event.target as HTMLSelectElement).value);
    if (Number.isFinite(value) && value !== this.pageSize) {
      this.pageSizeChange.emit(value);
    }
  }
}
